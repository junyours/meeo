<?php

namespace App\Http\Controllers;

use App\Models\SlaughterhouseCollection;
use App\Models\SlaughterhouseCollectionItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SlaughterhouseCollectionController extends Controller
{
    protected function itemRules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'animal_type' => ['required', 'string', 'max:255'],
            'total_kilos' => ['required', 'numeric', 'min:0'],
            'price_kilos' => ['required', 'numeric', 'min:0'],
            'ante_mortem' => ['nullable', 'numeric', 'min:0'],
            'post_mortem' => ['nullable', 'numeric', 'min:0'],
            'hides' => ['nullable', 'numeric', 'min:0'],
            'slaughter_fee' => ['nullable', 'numeric', 'min:0'],
            'coral_fee' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function calculateItemTotal(array $item): float
    {
        $priceKilos = (float) ($item['price_kilos'] ?? 0);
        $anteMortem = (float) ($item['ante_mortem'] ?? 0);
        $postMortem = (float) ($item['post_mortem'] ?? 0);
        $hides = (float) ($item['hides'] ?? 0);
        $slaughterFee = (float) ($item['slaughter_fee'] ?? 0);
        $coralFee = (float) ($item['coral_fee'] ?? 0);

        return round(($priceKilos + $anteMortem + $postMortem + $hides + $slaughterFee + $coralFee), 2);
    }

    protected function normalizeItem(array $item): array
    {
        return [
            'quantity' => (int) ($item['quantity'] ?? 0),
            'animal_type' => trim((string) ($item['animal_type'] ?? '')),
            'total_kilos' => (float) ($item['total_kilos'] ?? 0),
            'price_kilos' => (float) ($item['price_kilos'] ?? 0),
            'ante_mortem' => (float) ($item['ante_mortem'] ?? 0),
            'post_mortem' => (float) ($item['post_mortem'] ?? 0),
            'hides' => (float) ($item['hides'] ?? 0),
            'slaughter_fee' => (float) ($item['slaughter_fee'] ?? 0),
            'coral_fee' => (float) ($item['coral_fee'] ?? 0),
        ];
    }

    public function index(Request $request)
    {
        $query = SlaughterhouseCollection::query()
            ->orderBy('collection_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('date')) {
            $query->whereDate('collection_date', $request->date);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('collection_date', [$request->start_date, $request->end_date]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('or_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        $itemTotals = SlaughterhouseCollectionItem::query()
            ->select('slaughterhouse_collection_id')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as animal_count')
            ->selectRaw('COALESCE(SUM(total_kilos), 0) as total_kilos')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as grand_total')
            ->groupBy('slaughterhouse_collection_id');

        $summary = (clone $query)
            ->reorder()
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('slaughterhouse_collections.id', '=', 'item_totals.slaughterhouse_collection_id');
            })
            ->selectRaw('COUNT(slaughterhouse_collections.id) as total_collections')
            ->selectRaw('COALESCE(SUM(item_totals.item_count), 0) as animal_entries')
            ->selectRaw('COALESCE(SUM(item_totals.animal_count), 0) as animal_count')
            ->selectRaw('COALESCE(SUM(item_totals.grand_total), 0) as grand_total')
            ->first();

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $collections = $query
            ->select('id', 'collection_date', 'or_number', 'customer_name')
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->withSum('items as total_kilos', 'total_kilos')
            ->withSum('items as total_amount', 'total_amount')
            ->paginate($perPage)
            ->through(function (SlaughterhouseCollection $collection) {
                return [
                    'id' => $collection->id,
                    'collection_date' => $collection->collection_date,
                    'or_number' => $collection->or_number,
                    'customer_name' => $collection->customer_name,
                    'items_count' => (int) $collection->items_count,
                    'animal_count' => (int) ($collection->total_quantity ?? 0),
                    'total_kilos' => (float) ($collection->total_kilos ?? 0),
                    'grand_total' => (float) ($collection->total_amount ?? 0),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $collections,
            'summary' => [
                'total_collections' => (int) $summary->total_collections,
                'animal_entries' => (int) $summary->animal_entries,
                'animal_count' => (int) $summary->animal_count,
                'total_kilos' => round((float) $summary->total_kilos, 3),
                'grand_total' => round((float) $summary->grand_total, 2),
            ],
            'pagination' => [
                'current_page' => $collections->currentPage(),
                'per_page' => $collections->perPage(),
                'total' => $collections->total(),
                'last_page' => $collections->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $collection = SlaughterhouseCollection::with('items')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $collection,
        ]);
    }

    public function monthlySummary(Request $request)
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];
        $chargeFields = [
            'price_kilos',
            'ante_mortem',
            'post_mortem',
            'hides',
            'slaughter_fee',
            'coral_fee',
        ];
        $itemsForMonth = DB::table('slaughterhouse_collection_items as items')
            ->join('slaughterhouse_collections as collections', 'collections.id', '=', 'items.slaughterhouse_collection_id')
            ->whereNull('items.deleted_at')
            ->whereNull('collections.deleted_at')
            ->whereYear('collections.collection_date', $year)
            ->whereMonth('collections.collection_date', $month);

        $dailyTotals = (clone $itemsForMonth)
            ->selectRaw('DATE(collections.collection_date) as collection_day')
            ->selectRaw('COALESCE(SUM(items.quantity), 0) as animal_count')
            ->selectRaw('COALESCE(SUM(items.total_kilos), 0) as total_kilos')
            ->selectRaw('COALESCE(SUM(items.total_amount), 0) as grand_total')
            ->groupByRaw('DATE(collections.collection_date)')
            ->get()
            ->keyBy('collection_day');

        $dailyTransactionCounts = SlaughterhouseCollection::query()
            ->whereYear('collection_date', $year)
            ->whereMonth('collection_date', $month)
            ->selectRaw('DATE(collection_date) as collection_day')
            ->selectRaw('COUNT(*) as transaction_count')
            ->groupByRaw('DATE(collection_date)')
            ->pluck('transaction_count', 'collection_day');

        $animalTypes = (clone $itemsForMonth)
            ->selectRaw("COALESCE(NULLIF(TRIM(items.animal_type), ''), 'Unspecified') as animal_type")
            ->selectRaw('COALESCE(SUM(items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(items.total_kilos), 0) as total_kilos')
            ->selectRaw('COALESCE(SUM(items.total_amount), 0) as grand_total')
            ->groupByRaw("COALESCE(NULLIF(TRIM(items.animal_type), ''), 'Unspecified')")
            ->orderByDesc('grand_total')
            ->get()
            ->map(fn ($animal) => [
                'animal_type' => $animal->animal_type,
                'quantity' => (int) $animal->quantity,
                'total_kilos' => round((float) $animal->total_kilos, 3),
                'grand_total' => round((float) $animal->grand_total, 2),
            ])
            ->values();

        $chargeTotals = (clone $itemsForMonth)
            ->selectRaw('COALESCE(SUM(items.price_kilos), 0) as price_kilos')
            ->selectRaw('COALESCE(SUM(items.ante_mortem), 0) as ante_mortem')
            ->selectRaw('COALESCE(SUM(items.post_mortem), 0) as post_mortem')
            ->selectRaw('COALESCE(SUM(items.hides), 0) as hides')
            ->selectRaw('COALESCE(SUM(items.slaughter_fee), 0) as slaughter_fee')
            ->selectRaw('COALESCE(SUM(items.coral_fee), 0) as coral_fee')
            ->selectRaw('COALESCE(SUM(items.total_amount), 0) as grand_total')
            ->first();

        $charges = [];
        foreach ($chargeFields as $field) {
            $charges[$field] = round((float) ($chargeTotals->{$field} ?? 0), 2);
        }

        $monthStart = Carbon::create($year, $month, 1);
        $daily = [];
        for ($day = 1; $day <= $monthStart->daysInMonth; $day++) {
            $date = $monthStart->copy()->day($day)->format('Y-m-d');
            $totals = $dailyTotals->get($date);
            $daily[$date] = [
                'date' => $date,
                'transaction_count' => (int) ($dailyTransactionCounts[$date] ?? 0),
                'animal_count' => (int) ($totals->animal_count ?? 0),
                'total_kilos' => round((float) ($totals->total_kilos ?? 0), 3),
                'grand_total' => round((float) ($totals->grand_total ?? 0), 2),
            ];
        }

        $transactionCount = (int) $dailyTransactionCounts->sum();

        return response()->json([
            'status' => 'success',
            'data' => [
                'year' => $year,
                'month' => $month,
                'transaction_count' => $transactionCount,
                'animal_count' => (int) $dailyTotals->sum('animal_count'),
                'total_kilos' => round((float) $dailyTotals->sum('total_kilos'), 3),
                'grand_total' => round((float) ($chargeTotals->grand_total ?? 0), 2),
                'charges' => $charges,
                'animal_types' => $animalTypes,
                'daily' => array_values($daily),
            ],
        ]);
    }

    public function dailyDetails(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $collections = SlaughterhouseCollection::query()
            ->select('id', 'collection_date', 'or_number', 'customer_name')
            ->with(['items' => function ($query) {
                $query->select([
                    'id',
                    'slaughterhouse_collection_id',
                    'quantity',
                    'animal_type',
                    'total_kilos',
                    'price_kilos',
                    'ante_mortem',
                    'post_mortem',
                    'hides',
                    'slaughter_fee',
                    'coral_fee',
                    'total_amount',
                ]);
            }])
            ->whereDate('collection_date', $validated['date'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($collection) => [
                'id' => $collection->id,
                'or_number' => $collection->or_number,
                'customer_name' => $collection->customer_name,
                'grand_total' => round((float) $collection->items->sum('total_amount'), 2),
                'items' => $collection->items->map(fn ($item) => [
                    'id' => $item->id,
                    'quantity' => (int) $item->quantity,
                    'animal_type' => $item->animal_type,
                    'total_kilos' => (float) $item->total_kilos,
                    'price_kilos' => (float) $item->price_kilos,
                    'ante_mortem' => (float) $item->ante_mortem,
                    'post_mortem' => (float) $item->post_mortem,
                    'hides' => (float) $item->hides,
                    'slaughter_fee' => (float) $item->slaughter_fee,
                    'coral_fee' => (float) $item->coral_fee,
                    'total_amount' => (float) $item->total_amount,
                ])->values(),
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $collections,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'collection_date' => ['required', 'date'],
            'or_number' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.animal_type' => ['required', 'string', 'max:255'],
            'items.*.total_kilos' => ['required', 'numeric', 'min:0'],
            'items.*.price_kilos' => ['required', 'numeric', 'min:0'],
            'items.*.ante_mortem' => ['nullable', 'numeric', 'min:0'],
            'items.*.post_mortem' => ['nullable', 'numeric', 'min:0'],
            'items.*.hides' => ['nullable', 'numeric', 'min:0'],
            'items.*.slaughter_fee' => ['nullable', 'numeric', 'min:0'],
            'items.*.coral_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            DB::transaction(function () use ($validated, $request) {
                $collection = SlaughterhouseCollection::create([
                    'collection_date' => $validated['collection_date'],
                    'or_number' => trim($validated['or_number']),
                    'customer_name' => trim($validated['customer_name']),
                    'user_id' => $request->user()?->id,
                    'grand_total' => 0,
                ]);

                foreach ($validated['items'] as $itemPayload) {
                    $normalizedItem = $this->normalizeItem($itemPayload);
                    $collection->items()->create([
                        ...$normalizedItem,
                        'total_amount' => $this->calculateItemTotal($normalizedItem),
                    ]);
                }

                $collection->refresh();
                $collection->grand_total = (float) $collection->items()->sum('total_amount');
                $collection->save();

            });

            return response()->json([
                'status' => 'success',
                'message' => 'Slaughterhouse collection created successfully.',
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to save slaughterhouse collection.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $collection = SlaughterhouseCollection::findOrFail($id);

        $validated = $request->validate([
            'collection_date' => ['sometimes', 'date'],
            'or_number' => ['sometimes', 'string', 'max:255'],
            'customer_name' => ['sometimes', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.animal_type' => ['required', 'string', 'max:255'],
            'items.*.total_kilos' => ['required', 'numeric', 'min:0'],
            'items.*.price_kilos' => ['required', 'numeric', 'min:0'],
            'items.*.ante_mortem' => ['nullable', 'numeric', 'min:0'],
            'items.*.post_mortem' => ['nullable', 'numeric', 'min:0'],
            'items.*.hides' => ['nullable', 'numeric', 'min:0'],
            'items.*.slaughter_fee' => ['nullable', 'numeric', 'min:0'],
            'items.*.coral_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($collection, $validated, $request) {
            $collection->fill([
                'collection_date' => $validated['collection_date'] ?? $collection->collection_date,
                'or_number' => isset($validated['or_number']) ? trim($validated['or_number']) : $collection->or_number,
                'customer_name' => isset($validated['customer_name']) ? trim($validated['customer_name']) : $collection->customer_name,
                'user_id' => $request->user()?->id ?? $collection->user_id,
            ]);
            $collection->save();

            $incomingIds = [];
            foreach ($validated['items'] as $itemPayload) {
                $normalizedItem = $this->normalizeItem($itemPayload);
                $itemId = $itemPayload['id'] ?? null;

                if ($itemId) {
                    $existingItem = $collection->items()->whereKey($itemId)->first();
                    if ($existingItem) {
                        $existingItem->fill([
                            ...$normalizedItem,
                            'total_amount' => $this->calculateItemTotal($normalizedItem),
                        ]);
                        $existingItem->save();
                        $incomingIds[] = $existingItem->id;
                        continue;
                    }
                }

                $newItem = $collection->items()->create([
                    ...$normalizedItem,
                    'total_amount' => $this->calculateItemTotal($normalizedItem),
                ]);
                $incomingIds[] = $newItem->id;
            }

            $collection->items()->whereNotIn('id', $incomingIds)->delete();
            $collection->refresh();
            $collection->grand_total = (float) $collection->items()->sum('total_amount');
            $collection->save();

        });

        return response()->json([
            'status' => 'success',
            'message' => 'Slaughterhouse collection updated successfully.',
        ]);
    }

    public function destroy($id)
    {
        $collection = SlaughterhouseCollection::findOrFail($id);
        $collection->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Slaughterhouse collection deleted successfully.',
        ]);
    }

    public function dailySummary(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
        ]);

        $collectionDate = $request->date;

        $collections = SlaughterhouseCollection::with('items')
            ->whereDate('collection_date', $collectionDate)
            ->get();

        $summary = [
            'date' => $collectionDate,
            'transaction_count' => $collections->count(),
            'animal_count' => $collections->sum(fn ($collection) => $collection->items->sum('quantity')),
            'total_kilos' => round($collections->sum(fn ($collection) => $collection->items->sum('total_kilos')), 3),
            'grand_total' => round($collections->sum('grand_total'), 2),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $summary,
        ]);
    }
}
