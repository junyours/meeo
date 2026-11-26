<?php

namespace App\Http\Controllers;

use App\Models\Rented;
use App\Models\Stalls;
use App\Models\VendorOtherPayment;
use App\Models\VendorDetails;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VendorOtherPaymentController extends Controller
{
    private const ANNUAL_PAYMENT_TYPES = ['certification', 'stall_rights', 'space_right', 'dst'];

    private const WALK_IN_PAYMENT_TYPES = ['special_permit', 'rental_fee', 'electricity'];

    private const CERTIFICATE_PAYMENT_TYPES = ['certification'];

    private const RIGHTS_PAYMENT_TYPES = ['stall_rights', 'space_right'];

    private const ACTIVE_RENTAL_STATUSES = ['active', 'occupied', 'advance', 'temp_closed', 'partial', 'fully paid'];

    public function report(Request $request)
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
        ]);

        $paymentTypes = self::ANNUAL_PAYMENT_TYPES;
        $vendors = VendorDetails::query()
            ->with(['rented' => function ($query) {
                $query->whereIn('status', self::ACTIVE_RENTAL_STATUSES)
                    ->with('stall.section');
            }])
            ->select('id', 'first_name', 'middle_name', 'last_name', 'status')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $paymentsByVendorAndType = VendorOtherPayment::query()
            ->paid()
            ->whereYear('payment_date', $validated['year'])
            ->whereIn('payment_type', $paymentTypes)
            ->get(['id', 'vendor_id', 'payment_type', 'or_number', 'amount', 'payment_date'])
            ->sortByDesc('payment_date')
            ->groupBy(fn ($payment) => $payment->vendor_id.'|'.$payment->payment_type);

        $reportVendors = $vendors->map(function ($vendor) use ($paymentTypes, $paymentsByVendorAndType) {
            $eligiblePaymentTypes = $vendor->rented
                ->map(fn ($rental) => match ($rental->stall?->section?->rights_type) {
                    'stall_right' => 'stall_rights',
                    'space_right' => 'space_right',
                    default => null,
                })
                ->filter()
                ->unique()
                ->values();

            $payments = collect($paymentTypes)->mapWithKeys(function ($paymentType) use ($vendor, $paymentsByVendorAndType) {
                $payment = $paymentsByVendorAndType->get($vendor->id.'|'.$paymentType)?->first();

                return [$paymentType => $payment ? [
                    'id' => $payment->id,
                    'or_number' => $payment->or_number,
                    'amount' => $payment->amount,
                    'payment_date' => $payment->payment_date->toDateString(),
                ] : null];
            });

            return [
                'id' => $vendor->id,
                'name' => $vendor->full_name,
                'status' => $vendor->status,
                'eligible_payment_types' => $eligiblePaymentTypes->all(),
                'payments' => $payments,
            ];
        });

        $isEligibleForPaymentType = static function ($vendor, string $paymentType): bool {
            return ! in_array($paymentType, self::RIGHTS_PAYMENT_TYPES, true)
                || in_array($paymentType, $vendor['eligible_payment_types'], true);
        };

        return response()->json([
            'year' => (int) $validated['year'],
            'payment_types' => $paymentTypes,
            'summary' => [
                'vendor_count' => $reportVendors->count(),
                'paid_vendor_count' => $reportVendors->filter(fn ($vendor) => collect($vendor['payments'])->contains(
                    fn ($payment, $type) => $payment !== null && $isEligibleForPaymentType($vendor, $type)
                ))->count(),
                'payment_counts' => collect($paymentTypes)->mapWithKeys(fn ($paymentType) => [
                    $paymentType => $reportVendors->filter(fn ($vendor) => $vendor['payments'][$paymentType] !== null
                        && $isEligibleForPaymentType($vendor, $paymentType))->count(),
                ]),
                'eligible_vendor_counts' => collect($paymentTypes)->mapWithKeys(fn ($paymentType) => [
                    $paymentType => $reportVendors->filter(fn ($vendor) => $isEligibleForPaymentType($vendor, $paymentType))->count(),
                ]),
            ],
            'vendors' => $reportVendors->values(),
        ]);
    }

    public function payerReport(Request $request)
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
        ]);
        $otherPayerPaymentTypes = array_merge(
            self::WALK_IN_PAYMENT_TYPES,
            self::CERTIFICATE_PAYMENT_TYPES
        );

        $payments = VendorOtherPayment::query()
            ->whereYear('payment_date', $validated['year'])
            ->where(function ($query) use ($otherPayerPaymentTypes) {
                $query->whereIn('payer_type', ['Walk in Vendor', 'Driver'])
                    ->orWhere(function ($query) use ($otherPayerPaymentTypes) {
                        $query->whereNull('payer_type')
                            ->whereNull('vendor_id')
                            ->whereIn('payment_type', $otherPayerPaymentTypes);
                    });
            })
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get([
                'id',
                'payer_name',
                'payer_type',
                'vendor_id',
                'payment_type',
                'or_number',
                'amount',
                'payment_date',
                'status',
            ])
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'payer_name' => $payment->payer_name,
                    'payer_type' => $payment->payer_type ?: (
                        $payment->payment_type === 'certification' ? 'Driver' : 'Walk in Vendor'
                    ),
                    'payment_type' => $payment->payment_type,
                    'or_number' => $payment->or_number,
                    'amount' => $payment->amount,
                    'payment_date' => $payment->payment_date->toDateString(),
                    'status' => $payment->status,
                ];
            })
            ->values();

        return response()->json([
            'year' => (int) $validated['year'],
            'payments' => $payments,
        ]);
    }

    public function adminPayerReport(Request $request)
    {
        return $this->payerReport($request);
    }

    public function staffPayerReport(Request $request)
    {
        return $this->payerReport($request);
    }

    public function eligibility(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendor_details,id'],
            'year' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
        ]);

        $paidTypes = VendorOtherPayment::query()
            ->paid()
            ->where('vendor_id', $validated['vendor_id'])
            ->whereYear('payment_date', $validated['year'])
            ->distinct()
            ->pluck('payment_type');

        $rentals = Rented::query()
            ->with('stall.section')
            ->where('vendor_id', $validated['vendor_id'])
            ->whereIn('status', self::ACTIVE_RENTAL_STATUSES)
            ->get();

        $availablePaymentTypes = $rentals
            ->map(function ($rental) {
                return match ($rental->stall?->section?->rights_type) {
                    'stall_right' => 'stall_rights',
                    'space_right' => 'space_right',
                    default => null,
                };
            })
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            'vendor_id' => (int) $validated['vendor_id'],
            'year' => (int) $validated['year'],
            'paid_types' => $paidTypes,
            'available_payment_types' => $availablePaymentTypes,
        ]);
    }

    public function store(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'payments' => ['required', 'array', 'min:1', 'max:50'],
            'payments.*.vendor_id' => ['nullable', 'integer', 'exists:vendor_details,id'],
            'payments.*.stall_id' => ['nullable', 'integer', 'exists:stall,id'],
            'payments.*.section_id' => ['nullable', 'integer', 'exists:section,id'],
            'payments.*.rented_id' => ['nullable', 'integer', 'exists:rented,id'],
            'payments.*.payer_name' => ['nullable', 'string', 'max:255'],
            'payments.*.payer_type' => ['required', 'string', Rule::in(['Vendor', 'Walk in Vendor', 'Driver'])],
            'payments.*.payment_type' => ['required', 'string', Rule::in([
                'stall_rights',
                'space_right',
                'certification',
                'dst',
                'special_permit',
                'rental_fee',
                'electricity',
            ])],
            'payments.*.or_number' => ['required', 'string', 'max:100'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'payments.*.payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ])->validate();

        $payerTypes = collect($validated['payments'])->pluck('payer_type')->unique();
        if ($payerTypes->count() !== 1) {
            throw ValidationException::withMessages([
                'payments' => 'Payments with different payer types must be recorded separately.',
            ]);
        }

        foreach ($validated['payments'] as $index => $payment) {
            $isWalkInPayment = in_array($payment['payment_type'], self::WALK_IN_PAYMENT_TYPES, true);
            $isCertificatePayment = in_array($payment['payment_type'], self::CERTIFICATE_PAYMENT_TYPES, true);
            $expectedPayerType = $isWalkInPayment
                ? 'Walk in Vendor'
                : ($isCertificatePayment
                    ? (empty($payment['vendor_id']) ? 'Driver' : 'Vendor')
                    : 'Vendor');

            if ($payment['payer_type'] !== $expectedPayerType) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payer_type" => "The selected payment type requires payer type {$expectedPayerType}.",
                ]);
            }

            $requiresVendor = $payment['payer_type'] === 'Vendor';
            if (! $requiresVendor && ! empty($payment['vendor_id'])) {
                throw ValidationException::withMessages([
                    "payments.{$index}.vendor_id" => 'Walk-in and driver certificate payments cannot be linked to a vendor account.',
                ]);
            }

            if (($isWalkInPayment || ($isCertificatePayment && $payment['payer_type'] === 'Driver'))
                && empty(trim($payment['payer_name'] ?? ''))) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payer_name" => $isCertificatePayment
                        ? "The driver's name is required for certificate issuance."
                        : 'A payer name is required for walk-in payments.',
                ]);
            }

            if ($requiresVendor && empty($payment['vendor_id'])) {
                throw ValidationException::withMessages([
                    "payments.{$index}.vendor_id" => 'A vendor is required for annual fee payments.',
                ]);
            }
        }

        $hasAnnualPayments = collect($validated['payments'])->contains(
            fn ($payment) => $payment['payer_type'] === 'Vendor'
                && in_array($payment['payment_type'], self::ANNUAL_PAYMENT_TYPES, true)
        );
        $vendorIds = collect($validated['payments'])->pluck('vendor_id')->unique();
        if ($hasAnnualPayments && $vendorIds->count() !== 1) {
            throw ValidationException::withMessages([
                'payments' => 'All payment records in a batch must belong to the same vendor.',
            ]);
        }

        foreach ($validated['payments'] as $index => $payment) {
            $hasRentalReferences = ! empty($payment['stall_id'])
                || ! empty($payment['section_id'])
                || ! empty($payment['rented_id']);
            $requiresRental = in_array($payment['payment_type'], ['stall_rights', 'space_right'], true);

            if (($requiresRental || $hasRentalReferences)
                && (empty($payment['stall_id']) || empty($payment['section_id']) || empty($payment['rented_id']))) {
                throw ValidationException::withMessages([
                    "payments.{$index}.rented_id" => 'A matching vendor rental, stall, and section are required for this payment.',
                ]);
            }

            if (! $hasRentalReferences) {
                continue;
            }

            $rental = Rented::query()
                ->whereKey($payment['rented_id'])
                ->where('vendor_id', $payment['vendor_id'])
                ->where('stall_id', $payment['stall_id'])
                ->first();

            if (! $rental || ! in_array($rental->status, self::ACTIVE_RENTAL_STATUSES, true)) {
                throw ValidationException::withMessages([
                    "payments.{$index}.rented_id" => 'The selected rental is not active for this vendor and stall.',
                ]);
            }

            $stall = Stalls::query()
                ->with('section')
                ->whereKey($payment['stall_id'])
                ->where('section_id', $payment['section_id'])
                ->first();

            if (! $stall) {
                throw ValidationException::withMessages([
                    "payments.{$index}.section_id" => 'The selected section does not match the stall.',
                ]);
            }

            $requiredSectionRightsType = match ($payment['payment_type']) {
                'stall_rights' => 'stall_right',
                'space_right' => 'space_right',
                default => null,
            };

            if ($requiredSectionRightsType
                && $stall->section?->rights_type !== $requiredSectionRightsType) {
                throw ValidationException::withMessages([
                    "payments.{$index}.section_id" => 'The selected stall does not belong to a section with this rights type.',
                ]);
            }
        }

        $paymentGroups = collect($validated['payments'])->groupBy(function ($payment) {
            return implode('|', [
                $payment['payment_type'],
                Carbon::parse($payment['payment_date'])->year,
            ]);
        });

        $createdPayments = DB::transaction(function () use ($validated, $vendorIds, $paymentGroups) {
            $vendorId = $vendorIds->first();
            if ($vendorId) {
                VendorDetails::query()->whereKey($vendorId)->lockForUpdate()->firstOrFail();
            }

            foreach ($paymentGroups as $payments) {
                $payment = $payments->first();
                if ($payment['payer_type'] !== 'Vendor'
                    || ! in_array($payment['payment_type'], self::ANNUAL_PAYMENT_TYPES, true)) {
                    continue;
                }

                $year = Carbon::parse($payment['payment_date'])->year;
                $alreadyPaid = VendorOtherPayment::query()
                    ->paid()
                    ->where('vendor_id', $vendorId)
                    ->where('payment_type', $payment['payment_type'])
                    ->whereYear('payment_date', $year)
                    ->exists();

                if ($alreadyPaid) {
                    throw ValidationException::withMessages([
                        'payments' => ucfirst(str_replace('_', ' ', $payment['payment_type']))
                            ." has already been paid by this vendor in {$year}.",
                    ]);
                }
            }

            return collect($validated['payments'])->map(function ($payment) {
                $payment['status'] = 'paid';

                return VendorOtherPayment::create($payment);
            });
        });

        return response()->json([
            'message' => 'Vendor other payments recorded successfully.',
            'data' => $createdPayments,
        ], 201);
    }
}