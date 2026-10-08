<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VendorPaymentController;
use App\Models\Collection;
use App\Models\CollectionSession;
use App\Models\CollectionTurnover;
use App\Models\Rented;
use App\Models\VendorDetails;
use App\Models\VendorQrCode;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileCollectorController extends Controller
{
    private const CURRENT_RENTAL_STATUSES = [
        'active',
        'occupied',
        'advance',
        'temp_closed',
        'partial',
        'fully paid',
    ];

    public function __construct(
        private readonly VendorPaymentController $payments
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(
                in_array($request->user()?->role, ['collector', 'incharge_collector', 'main_collector'], true),
                403,
                'Collector access is required.'
            );

            return $next($request);
        });
    }

    public function bootstrap(Request $request)
    {
        $vendors = VendorDetails::query()
            ->where('status', 'active')
            ->whereHas('rented', fn ($query) => $query
                ->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                ->whereHas('stall'))
            ->with([
                'qrCode' => fn ($query) => $query
                    ->where('is_active', true)
                    ->select('id', 'vendor_id', 'qr_token'),
                'rented' => fn ($query) => $query
                    ->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                    ->whereHas('stall')
                    ->with([
                        'stall:id,stall_number,section_id,is_monthly,monthly_rate',
                        'stall.section:id,name,area_id',
                        'stall.section.area:id,name',
                    ]),
            ])
            ->orderBy('first_name')
            ->get([
                'id',
                'first_name',
                'middle_name',
                'last_name',
                'contact_number',
                'address',
                'status',
            ]);

        return response()->json([
            'collector' => [
                'id' => $request->user()->id,
                'username' => $request->user()->username,
                'email' => $request->user()->email,
                'role' => $request->user()->role,
            ],
            'vendors' => $vendors
                ->map(fn (VendorDetails $vendor) => $this->formatVendor($vendor)),
            'last_synced_at' => now()->toIso8601String(),
        ]);
    }

    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $term = trim($validated['query']);

        $vendors = $this->vendorQuery()
            ->where(function ($query) use ($term) {
                $query->where('first_name', 'like', "%{$term}%")
                    ->orWhere('middle_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('contact_number', 'like', "%{$term}%")
                    ->orWhereRaw(
                        "CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?",
                        ["%{$term}%"]
                    );
            })
            ->limit(50)
            ->get();

        return response()->json([
            'vendors' => $vendors
                ->map(fn (VendorDetails $vendor) => $this->formatVendor($vendor)),
        ]);
    }

    public function scan(string $token)
    {
        $qrCode = VendorQrCode::query()
            ->where('qr_token', $token)
            ->where('is_active', true)
            ->with(['vendor' => fn ($query) => $query->with([
                'rented' => fn ($rentals) => $rentals
                    ->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                    ->whereHas('stall')
                    ->with([
                        'stall:id,stall_number,section_id,is_monthly,monthly_rate',
                        'stall.section:id,name,area_id',
                        'stall.section.area:id,name',
                    ]),
            ])])
            ->firstOrFail();

        abort_unless($qrCode->vendor && $qrCode->vendor->status === 'active', 404);
        abort_if($qrCode->vendor->rented->isEmpty(), 404, 'No active stalls found for this vendor.');

        return response()->json(['vendor' => $this->formatVendor($qrCode->vendor)]);
    }

    public function quote(Request $request)
    {
        $validated = $request->validate([
            'rented_id' => ['required_without:rented_ids', 'integer'],
            'rented_ids' => ['required_without:rented_id', 'array', 'min:1', 'max:20'],
            'rented_ids.*' => ['required', 'integer', 'distinct'],
            'amount_to_pay' => ['required', 'numeric', 'min:0.01'],
        ]);

        $amount = (float) $validated['amount_to_pay'];
        $rentedIds = $validated['rented_ids'] ?? [$validated['rented_id']];
        $rentals = $this->loadCollectionRentals($rentedIds);
        if (count($rentals) === 1 && ! isset($validated['rented_ids'])) {
            $quote = $this->paymentQuote($rentals[0], $amount);

            return response()->json([
                'rented_id' => $rentals[0]->id,
                'amount_to_pay' => number_format($amount, 2, '.', ''),
                ...$quote,
            ]);
        }

        $shares = $this->allocateAmountByDailyRent($rentals, $amount);
        $allocations = [];
        foreach ($rentals as $rental) {
            $share = $shares[$rental->id];
            $allocations[] = [
                'rented_id' => $rental->id,
                'stall_number' => $rental->stall?->stall_number ?? '—',
                'section_name' => $rental->stall?->section?->name ?? '—',
                'amount_to_pay' => number_format($share, 2, '.', ''),
                ...$this->paymentQuote($rental, $share),
            ];
        }

        return response()->json([
            'amount_to_pay' => number_format($amount, 2, '.', ''),
            'payment_type' => count(array_unique(array_column($allocations, 'payment_type'))) === 1
                ? $allocations[0]['payment_type']
                : 'combined',
            'days_covered' => array_sum(array_column($allocations, 'days_covered')),
            'allocations' => $allocations,
        ]);
    }

    public function createCollection(Request $request)
    {
        $validated = $request->validate([
            'client_transaction_id' => ['required', 'uuid'],
            'rented_id' => ['required_without:allocations', 'integer'],
            'amount_to_pay' => ['required', 'numeric', 'min:0.01'],
            'collection_date' => ['nullable', 'date', 'before_or_equal:today'],
            'rental_snapshot' => ['nullable', 'array'],
            'rental_snapshot.status' => ['required_with:rental_snapshot', 'string'],
            'rental_snapshot.daily_rent' => ['required_with:rental_snapshot', 'numeric'],
            'rental_snapshot.monthly_rent' => ['required_with:rental_snapshot', 'numeric'],
            'rental_snapshot.missed_days' => ['required_with:rental_snapshot', 'integer'],
            'rental_snapshot.remaining_balance' => ['required_with:rental_snapshot', 'numeric'],
            'rental_snapshot.next_due_date' => ['nullable', 'date'],
            'rental_snapshot.last_payment_date' => ['nullable', 'date'],
            'rental_snapshot.is_monthly' => ['required_with:rental_snapshot', 'boolean'],
            'allocations' => ['required_without:rented_id', 'array', 'min:1', 'max:20'],
            'allocations.*.rented_id' => ['required', 'integer', 'distinct'],
            'allocations.*.rental_snapshot' => ['required', 'array'],
            'allocations.*.rental_snapshot.status' => ['required', 'string'],
            'allocations.*.rental_snapshot.daily_rent' => ['required', 'numeric'],
            'allocations.*.rental_snapshot.monthly_rent' => ['required', 'numeric'],
            'allocations.*.rental_snapshot.missed_days' => ['required', 'integer'],
            'allocations.*.rental_snapshot.remaining_balance' => ['required', 'numeric'],
            'allocations.*.rental_snapshot.next_due_date' => ['nullable', 'date'],
            'allocations.*.rental_snapshot.last_payment_date' => ['nullable', 'date'],
            'allocations.*.rental_snapshot.is_monthly' => ['required', 'boolean'],
        ]);

        $requestedAllocations = $validated['allocations'] ?? [[
            'rented_id' => $validated['rented_id'],
            'rental_snapshot' => $validated['rental_snapshot'] ?? null,
        ]];
        $requestedIds = array_map('intval', array_column($requestedAllocations, 'rented_id'));
        $existing = Collection::query()
            ->where('client_transaction_id', $validated['client_transaction_id'])
            ->with(['vendor', 'stall.section'])
            ->orderBy('id')
            ->get();
        if ($existing->isNotEmpty()) {
            abort_unless($existing->every(fn ($item) => $item->collector_id === $request->user()->id), 403);
            $existingIds = $existing->pluck('rented_id')->map(fn ($id) => (int) $id)->all();
            sort($requestedIds);
            sort($existingIds);
            abort_if(
                $existingIds !== $requestedIds
                || round((float) $existing->sum('amount_to_pay'), 2) !== round((float) $validated['amount_to_pay'], 2),
                409,
                'This transaction identifier has already been used for different collection details.'
            );

            return response()->json([
                'collection' => $this->formatCollectionGroup($existing),
                'replayed' => true,
            ]);
        }

        $result = DB::transaction(function () use ($request, $validated, $requestedAllocations, $requestedIds) {
            $session = CollectionSession::firstOrCreate(
                [
                    'collector_id' => $request->user()->id,
                    'collection_date' => $validated['collection_date'] ?? today()->toDateString(),
                ],
                ['status' => 'pending']
            );
            $session = CollectionSession::query()->lockForUpdate()->findOrFail($session->id);

            $existing = Collection::query()
                ->where('client_transaction_id', $validated['client_transaction_id'])
                ->with(['vendor', 'stall.section'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($existing->isNotEmpty()) {
                $existingIds = $existing->pluck('rented_id')->map(fn ($id) => (int) $id)->all();
                sort($existingIds);
                $sortedRequestedIds = $requestedIds;
                sort($sortedRequestedIds);
                abort_unless($existing->every(fn ($item) => $item->collector_id === $request->user()->id), 403);
                abort_if(
                    $existingIds !== $sortedRequestedIds
                    || round((float) $existing->sum('amount_to_pay'), 2) !== round((float) $validated['amount_to_pay'], 2),
                    409,
                    'This transaction identifier has already been used for different collection details.'
                );

                return ['collection' => $this->formatCollectionGroup($existing), 'replayed' => true];
            }

            abort_unless(
                in_array($session->status, ['pending', 'submitted'], true),
                409,
                'This collection session has already been verified and is closed.'
            );
            if ($session->status === 'submitted') {
                $session->update([
                    'status' => 'pending',
                    'verified_by' => null,
                    'verified_at' => null,
                    'remarks' => null,
                ]);
            }

            $rentals = $this->loadCollectionRentals($requestedIds, true);
            $snapshots = [];
            foreach ($requestedAllocations as $allocation) {
                $rental = collect($rentals)->firstWhere('id', (int) $allocation['rented_id']);
                $snapshot = $allocation['rental_snapshot'] ?? null;
                if ($snapshot === null && count($requestedAllocations) === 1) {
                    $snapshot = $validated['rental_snapshot'] ?? null;
                }
                $this->assertRentalSnapshotMatches($rental, $snapshot);
                $snapshots[$rental->id] = $snapshot;
            }

            $amount = (float) $validated['amount_to_pay'];
            $shares = $this->allocateAmountByDailyRent($rentals, $amount);
            $rows = new EloquentCollection(collect($rentals)->map(function ($rental) use ($request, $session, $validated, $shares, $snapshots) {
                $share = $shares[$rental->id];
                $quote = $this->paymentQuote($rental, $share);

                return Collection::create([
                    'collection_session_id' => $session->id,
                    'vendor_id' => $rental->vendor_id,
                    'rented_id' => $rental->id,
                    'stall_id' => $rental->stall_id,
                    'collector_id' => $request->user()->id,
                    'amount_to_pay' => $share,
                    'days_covered' => $quote['days_covered'],
                    'payment_type' => $quote['payment_type'],
                    'is_collected' => false,
                    'client_transaction_id' => $validated['client_transaction_id'],
                    'rental_snapshot' => $snapshots[$rental->id] ?? null,
                ]);
            })->all());
            $rows->load(['vendor', 'stall.section']);
            $this->refreshSessionTotals($session);

            return ['collection' => $this->formatCollectionGroup($rows), 'replayed' => false];
        });

        return response()->json(
            ['collection' => $result['collection'], 'replayed' => $result['replayed']],
            $result['replayed'] ? 200 : 201
        );
    }

    public function markCollected(Request $request, Collection $collection)
    {
        abort_unless($collection->collector_id === $request->user()->id, 404);

        $result = DB::transaction(function () use ($collection) {
            $session = CollectionSession::query()
                ->lockForUpdate()
                ->findOrFail($collection->collection_session_id);
            $query = Collection::query()
                ->where('client_transaction_id', $collection->client_transaction_id);
            if (! $collection->client_transaction_id) {
                $query->whereKey($collection->id);
            }
            $rows = $query->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->every(fn ($row) => $row->is_collected)) {
                $rows->load(['vendor', 'stall.section']);

                return $this->formatCollectionGroup($rows);
            }

            abort_unless(
                in_array($session->status, ['pending', 'submitted'], true),
                409,
                'This collection session has already been verified and is closed.'
            );
            foreach ($rows as $row) {
                if ($row->is_collected) {
                    continue;
                }
                $rental = Rented::with('stall')->lockForUpdate()->findOrFail($row->rented_id);
                abort_unless($rental->vendor_id === $row->vendor_id, 409, 'A collection stall no longer belongs to this vendor.');
                abort_unless($rental->vendor()->where('status', 'active')->exists(), 409, 'This vendor is no longer active.');
                abort_unless(in_array($rental->status, self::CURRENT_RENTAL_STATUSES, true), 409, 'This rental is no longer active.');
                $this->assertRentalSnapshotMatches($rental, $row->rental_snapshot);
                $quote = $this->paymentQuote($rental, (float) $row->amount_to_pay);
                $processed = $this->payments->processMobilePaymentForRental(
                    $rental,
                    (float) $row->amount_to_pay,
                    $quote['payment_type'],
                    now(),
                    $quote['advance_days'] ?: null
                );
                abort_unless($processed['success'] ?? false, 422, $processed['message'] ?? 'Payment could not be processed.');

                $row->payment_id = $processed['payment']->id;
                $row->payment_type = $processed['payment']->payment_type;
                $row->days_covered = $quote['days_covered'];
                $row->is_collected = true;
                $row->collected_at = now();
                $row->save();
            }

            $rowsQuery = Collection::query()
                ->with(['vendor', 'stall.section'])
                ->orderBy('id');
            if ($collection->client_transaction_id) {
                $rowsQuery->where('client_transaction_id', $collection->client_transaction_id);
            } else {
                $rowsQuery->whereKey($collection->id);
            }
            $rows = $rowsQuery->get();
            if ($session->status === 'submitted' && $rows->contains(
                fn (Collection $row) => $row->collection_turnover_id === null
            )) {
                $session->update([
                    'status' => 'pending',
                    'verified_by' => null,
                    'verified_at' => null,
                    'remarks' => null,
                ]);
            }
            $this->refreshSessionTotals($session);

            return $this->formatCollectionGroup($rows);
        });

        return response()->json(['collection' => $result]);
    }

    public function collections(Request $request)
    {
        $date = $request->validate([
            'date' => ['nullable', 'date'],
        ])['date'] ?? today()->toDateString();

        $session = CollectionSession::query()
            ->where([
                'collector_id' => $request->user()->id,
                'collection_date' => $date,
            ])
            ->first();
        if (! $session) {
            return response()->json([
                'session' => null,
                'summary' => [
                    'expected_total' => 0,
                    'collected_total' => 0,
                    'uncollected_total' => 0,
                    'collection_count' => 0,
                ],
            ]);
        }

        $this->refreshSessionTotals($session);
        $session->load(['collections.vendor', 'collections.stall.section']);
        $groups = $session->collections
            ->groupBy(fn ($item) => $item->client_transaction_id ?: "collection-{$item->id}")
            ->map(fn ($rows) => $this->formatCollectionGroup($rows))
            ->values();
        $session->setRelation('collections', $groups);

        return response()->json([
            'session' => $session,
            'summary' => [
                'expected_total' => $groups->sum('amount_to_pay'),
                'collected_total' => $groups->where('is_collected', true)->sum('amount_to_pay'),
                'uncollected_total' => $groups->where('is_collected', false)->sum('amount_to_pay'),
                'collection_count' => $groups->count(),
            ],
        ]);
    }

    public function turnover(Request $request)
    {
        $validated = $request->validate([
            'turnover_client_id' => ['required', 'uuid'],
            'cash_turned_over' => ['required', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'collection_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $session = DB::transaction(function () use ($request, $validated) {
            $session = CollectionSession::query()
                ->where('collector_id', $request->user()->id)
                ->whereDate('collection_date', $validated['collection_date'] ?? today())
                ->lockForUpdate()
                ->firstOrFail();

            $existingTurnover = CollectionTurnover::query()
                ->where('turnover_client_id', $validated['turnover_client_id'])
                ->first();
            if ($existingTurnover) {
                abort_unless($existingTurnover->collection_session_id === $session->id, 409);
                abort_if(
                    round((float) $existingTurnover->cash_turned_over, 2)
                        !== round((float) $validated['cash_turned_over'], 2)
                    || $existingTurnover->remarks !== ($validated['remarks'] ?? null),
                    409,
                    'This turnover identifier has already been used for different details.'
                );

                return $session->fresh();
            }

            $eligibleCollections = $session->collections()
                ->where('is_collected', true)
                ->whereNull('collection_turnover_id')
                ->lockForUpdate()
                ->get();
            abort_if(
                $eligibleCollections->isEmpty(),
                409,
                'There are no newly collected transactions waiting for turnover.'
            );

            $collectedTotal = (float) $eligibleCollections->sum('amount_to_pay');
            $cash = (float) $validated['cash_turned_over'];
            $turnover = CollectionTurnover::create([
                'collection_session_id' => $session->id,
                'turnover_client_id' => $validated['turnover_client_id'],
                'expected_total' => $collectedTotal,
                'collected_total' => $collectedTotal,
                'cash_turned_over' => $cash,
                'difference' => $cash - $collectedTotal,
                'remarks' => $validated['remarks'] ?? null,
                'submitted_at' => now(),
            ]);
            $session->collections()
                ->whereKey($eligibleCollections->modelKeys())
                ->update(['collection_turnover_id' => $turnover->id]);
            $session->update([
                'turnover_client_id' => $validated['turnover_client_id'],
                'status' => 'submitted',
                'remarks' => $validated['remarks'] ?? null,
            ]);
            $this->refreshSessionTotals($session);

            return $session->fresh();
        });

        return response()->json(['session' => $session]);
    }

    private function formatCollectionGroup(EloquentCollection $rows): Collection
    {
        /** @var Collection $primary */
        $primary = $rows->first();
        $amount = (float) $rows->sum('amount_to_pay');
        $daysCovered = (int) $rows->sum('days_covered');
        $paymentTypes = $rows->pluck('payment_type')->filter()->unique()->values();

        $primary->setAttribute('amount_to_pay', number_format($amount, 2, '.', ''));
        $primary->setAttribute('days_covered', $daysCovered);
        $primary->setAttribute(
            'payment_type',
            $paymentTypes->count() === 1 ? $paymentTypes->first() : 'combined'
        );
        $primary->setAttribute('is_collected', $rows->every(fn ($row) => $row->is_collected));
        $primary->setAttribute(
            'collected_at',
            $rows->where('is_collected', true)->max('collected_at')
        );

        if ($rows->count() > 1) {
            $primary->setRelation('allocations', $rows->map(fn ($row) => [
                'rented_id' => $row->rented_id,
                'stall_id' => $row->stall_id,
                'payment_id' => $row->payment_id,
                'amount_to_pay' => number_format((float) $row->amount_to_pay, 2, '.', ''),
                'days_covered' => $row->days_covered,
                'payment_type' => $row->payment_type,
                'rental_snapshot' => $row->rental_snapshot,
                'stall' => $row->stall,
            ])->values());
        }

        return $primary;
    }

    private function refreshSessionTotals(CollectionSession $session): void
    {
        $totals = $session->collections()
            ->selectRaw('COALESCE(SUM(amount_to_pay), 0) AS expected_total')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN is_collected = ? THEN amount_to_pay ELSE 0 END), 0) AS collected_total',
                [true]
            )
            ->first();

        $updates = [];
        if (round((float) $session->expected_total, 2) !== round((float) $totals->expected_total, 2)) {
            $updates['expected_total'] = $totals->expected_total;
        }
        if (round((float) $session->collected_total, 2) !== round((float) $totals->collected_total, 2)) {
            $updates['collected_total'] = $totals->collected_total;
        }
        $cashTurnedOver = (float) $session->turnovers()->sum('cash_turned_over');
        if (round((float) $session->cash_turned_over, 2) !== round($cashTurnedOver, 2)) {
            $updates['cash_turned_over'] = $cashTurnedOver;
        }
        $difference = $cashTurnedOver - (float) $totals->collected_total;
        if (round((float) $session->difference, 2) !== round($difference, 2)) {
            $updates['difference'] = $difference;
        }
        if ($updates !== []) {
            $session->update($updates);
        }
    }

    private function loadCollectionRentals(array $rentedIds, bool $lock = false): array
    {
        abort_if(count($rentedIds) < 1 || count($rentedIds) > 20, 422, 'Select between one and 20 stalls.');
        abort_if(count(array_unique($rentedIds)) !== count($rentedIds), 422, 'A stall cannot be selected more than once.');

        $query = Rented::with(['stall.section', 'vendor'])
            ->whereIn('status', self::CURRENT_RENTAL_STATUSES)
            ->whereIn('id', $rentedIds);
        if ($lock) {
            $query->lockForUpdate();
        }

        $rentalsById = $query->get()->keyBy('id');
        abort_unless($rentalsById->count() === count($rentedIds), 404, 'One or more selected stalls are no longer available.');
        $rentals = collect($rentedIds)->map(fn ($id) => $rentalsById->get($id))->all();
        $vendorIds = collect($rentals)->pluck('vendor_id')->unique();
        abort_unless($vendorIds->count() === 1, 422, 'All selected stalls must belong to the same vendor.');
        abort_unless($rentals[0]->vendor?->status === 'active', 404, 'Vendor is not active.');

        return $rentals;
    }

    private function allocateAmountByDailyRent(array $rentals, float $amount): array
    {
        $weights = [];
        foreach ($rentals as $rental) {
            $monthly = (bool) $rental->stall?->is_monthly;
            $rent = (float) ($monthly ? $rental->monthly_rent : $rental->daily_rent);
            if ($monthly && $rent <= 0) {
                $rent = (float) $rental->stall?->monthly_rate;
            }
            $weights[$rental->id] = $monthly ? $rent / 30 : $rent;
            abort_if($weights[$rental->id] <= 0, 422, 'A selected stall has no valid rent for payment allocation.');
        }

        $totalWeight = array_sum($weights);
        $totalCents = (int) round($amount * 100);
        $shares = [];
        $remainders = [];
        $allocatedCents = 0;
        foreach ($weights as $rentedId => $weight) {
            $exactShare = ($totalCents * $weight) / $totalWeight;
            $wholeCents = (int) floor($exactShare);
            $shares[$rentedId] = $wholeCents;
            $allocatedCents += $wholeCents;
            $remainders[$rentedId] = $exactShare - $wholeCents;
        }

        arsort($remainders);
        $remainingCents = $totalCents - $allocatedCents;
        foreach (array_keys($remainders) as $rentedId) {
            if ($remainingCents <= 0) {
                break;
            }
            $shares[$rentedId]++;
            $remainingCents--;
        }

        $amounts = [];
        foreach ($shares as $rentedId => $cents) {
            abort_if($cents < 1, 422, 'The amount is too small to allocate at least one cent to every selected stall.');
            $amounts[$rentedId] = $cents / 100;
        }

        return $amounts;
    }

    private function vendorQuery()
    {
        return VendorDetails::query()
            ->where('status', 'active')
            ->whereHas('rented', fn ($query) => $query
                ->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                ->whereHas('stall'))
            ->with([
                'qrCode' => fn ($query) => $query->where('is_active', true),
                'rented' => fn ($query) => $query
                    ->whereIn('status', self::CURRENT_RENTAL_STATUSES)
                    ->whereHas('stall')
                    ->with(['stall.section.area']),
            ]);
    }

    private function formatVendor(VendorDetails $vendor): array
    {
        $formatted = $vendor->toArray();
        $formatted['rented'] = $vendor->rented
            ->map(static function (Rented $rental) {
                $formattedRental = $rental->toArray();
                $formattedRental['next_due_date'] = $rental->next_due_date?->toDateString();
                $formattedRental['last_payment_date'] = $rental->last_payment_date?->toDateString();

                return $formattedRental;
            })
            ->all();

        return $formatted;
    }

    public function paymentQuote(Rented $rental, float $amount): array
    {
        $paymentDate = now();
        $monthly = (bool) ($rental->stall?->is_monthly);
        $dailyRent = (float) ($rental->daily_rent ?? 0);
        $monthlyRent = (float) ($rental->monthly_rent ?? 0);
        if ($monthlyRent <= 0) {
            $monthlyRent = (float) ($rental->stall?->monthly_rate ?? 0);
        }

        if ($monthly) {
            abort_if($monthlyRent <= 0, 422, 'Invalid monthly rent for this monthly stall.');
            abort_if($amount < $monthlyRent, 422, 'The amount must cover at least one monthly rent payment.');

            return [
                'payment_type' => 'monthly',
                'days_covered' => 0,
                'missed_days_covered' => 0,
                'advance_days' => 0,
            ];
        }

        abort_if($dailyRent <= 0, 422, 'Invalid daily rent for this rental.');
        $missed = max((int) $rental->missed_days, (int) ceil(
            (float) ($rental->remaining_balance ?? 0) / $dailyRent
        ));
        $todayDue = $rental->last_payment_date?->toDateString() === $paymentDate->toDateString()
            ? 0
            : $dailyRent;
        $required = ((float) ($rental->remaining_balance ?? 0)) + $todayDue;
        if ($required <= $todayDue) {
            $required = $missed * $dailyRent + $todayDue;
        }

        if ($amount < $required) {
            $days = min($missed, (int) floor($amount / $dailyRent));

            return [
                'payment_type' => 'partial',
                'days_covered' => $days,
                'missed_days_covered' => $days,
                'advance_days' => 0,
            ];
        }

        $advanceDays = max(0, (int) floor(($amount - $required) / $dailyRent));

        return [
            'payment_type' => $advanceDays > 0 ? 'advance' : 'fully paid',
            'days_covered' => $missed + ($todayDue > 0 ? 1 : 0) + $advanceDays,
            'missed_days_covered' => $missed,
            'advance_days' => $advanceDays,
        ];
    }

    private function assertRentalSnapshotMatches(Rented $rental, ?array $snapshot): void
    {
        if ($snapshot === null) {
            return;
        }

        $current = [
            'status' => $rental->status,
            'daily_rent' => (float) $rental->daily_rent,
            'monthly_rent' => (float) $rental->monthly_rent,
            'missed_days' => (int) $rental->missed_days,
            'remaining_balance' => (float) $rental->remaining_balance,
            'next_due_date' => $rental->next_due_date?->toDateString(),
            'last_payment_date' => $rental->last_payment_date?->toDateString(),
            'is_monthly' => (bool) $rental->stall?->is_monthly,
        ];

        foreach ($current as $field => $value) {
            $cached = $snapshot[$field] ?? null;
            if (in_array($field, ['daily_rent', 'monthly_rent', 'remaining_balance'], true)) {
                $matches = round((float) $cached, 2) === round((float) $value, 2);
            } elseif (in_array($field, ['next_due_date', 'last_payment_date'], true)) {
                $matches = $cached === $value;
            } else {
                $matches = $cached === $value;
            }

            abort_unless(
                $matches,
                409,
                "Rental information changed since it was cached ({$field}). Review this collection before syncing."
            );
        }
    }
}
