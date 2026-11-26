<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Rented;
use App\Models\VendorDetails;
use App\Services\StallRateHistoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MobileVendorBalancesController extends Controller
{
    private const ACTIVE_RENTAL_STATUSES = [
        'active',
        'occupied',
        'advance',
        'temp_closed',
        'partial',
        'fully paid',
    ];

    public function __construct(
        private readonly StallRateHistoryService $rateHistoryService
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

    public function index(Request $request)
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ]);
        $year = (int) ($validated['year'] ?? now()->year);

        $vendors = VendorDetails::query()
            ->where('status', 'active')
            ->whereHas('rented', fn ($query) => $query
                ->whereIn('status', self::ACTIVE_RENTAL_STATUSES)
                ->whereHas('stall'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name']);

        return response()->json([
            'year' => $year,
            'vendors' => $vendors->map(fn (VendorDetails $vendor) => [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->full_name,
            ])->values(),
        ]);
    }

    public function show(Request $request, int $vendorId)
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ]);
        $year = (int) ($validated['year'] ?? now()->year);
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $vendor = VendorDetails::query()
            ->where('status', 'active')
            ->with(['rented' => function ($query) use ($year, $yearStart, $yearEnd) {
                $query->where(function ($rentals) use ($year) {
                    $rentals->whereIn('status', self::ACTIVE_RENTAL_STATUSES)
                        ->orWhere(function ($removed) use ($year) {
                            $removed->where('status', 'unoccupied')
                                ->whereYear('created_at', '<=', $year)
                                ->where(function ($dates) use ($year) {
                                    $dates->whereYear('created_at', $year)
                                        ->orWhereYear('updated_at', $year);
                                });
                        });
                })->with([
                    'stall:id,stall_number,section_id,is_monthly,daily_rate,monthly_rate',
                    'stall.section:id,name',
                    'payments' => fn ($payments) => $payments
                        ->whereIn('status', ['paid', 'collected'])
                        ->whereBetween('payment_date', [$yearStart, $yearEnd]),
                ]);
            }])
            ->findOrFail($vendorId);

        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $sections = [];
        $paymentDaysByMonth = array_fill(0, 12, []);

        foreach ($vendor->rented as $rental) {
            $stall = $rental->stall;
            $section = $stall?->section;
            if (! $stall || ! $section) {
                continue;
            }

            $sectionKey = (string) $section->id;
            $stallKey = (string) $stall->id;
            if (! isset($sections[$sectionKey])) {
                $sections[$sectionKey] = [
                    'section_id' => $section->id,
                    'section_name' => $section->name,
                    'stalls' => [],
                ];
            }
            if (! isset($sections[$sectionKey]['stalls'][$stallKey])) {
                $sections[$sectionKey]['stalls'][$stallKey] = [
                    'stall_id' => $stall->id,
                    'stall_number' => $stall->stall_number,
                    'monthly_balances' => array_map(
                        fn ($month) => [
                            'month' => $month,
                            'monthly_rate' => 0.0,
                            'payment' => 0.0,
                            'balance' => 0.0,
                        ],
                        $monthNames
                    ),
                ];
            }

            $paymentsByMonth = array_fill(0, 12, 0.0);
            foreach ($rental->payments as $payment) {
                $paymentDate = Carbon::parse($payment->payment_date);
                $monthIndex = $paymentDate->month - 1;
                $paymentsByMonth[$monthIndex] += (float) $payment->amount;

                $dateKey = $paymentDate->toDateString();
                if (! isset($paymentDaysByMonth[$monthIndex][$dateKey])) {
                    $paymentDaysByMonth[$monthIndex][$dateKey] = [
                        'date' => $dateKey,
                        'amount' => 0.0,
                        'or_numbers' => [],
                    ];
                }
                $paymentDaysByMonth[$monthIndex][$dateKey]['amount'] += (float) $payment->amount;
                if ($payment->or_number && ! in_array(
                    (string) $payment->or_number,
                    $paymentDaysByMonth[$monthIndex][$dateKey]['or_numbers'],
                    true
                )) {
                    $paymentDaysByMonth[$monthIndex][$dateKey]['or_numbers'][] = (string) $payment->or_number;
                }
            }

            $rentalStart = Carbon::parse($rental->created_at)->startOfDay();
            $rentalEnd = $rental->status === 'unoccupied' && $rental->updated_at
                ? Carbon::parse($rental->updated_at)->endOfDay()
                : null;

            foreach ($monthNames as $monthIndex => $monthName) {
                $monthStart = Carbon::create($year, $monthIndex + 1, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();
                $monthlyRate = 0.0;

                if (! $rentalStart->greaterThan($monthEnd) && ! ($rentalEnd && $rentalEnd->lessThan($monthStart))) {
                    for ($day = 1; $day <= $monthStart->daysInMonth; $day++) {
                        $date = Carbon::create($year, $monthIndex + 1, $day)->startOfDay();
                        if ($date->lessThan($rentalStart) || ($rentalEnd && $date->greaterThan($rentalEnd))) {
                            continue;
                        }
                        $monthlyRate += $this->rateHistoryService->getDailyRentForRentalDate($rental, $date);
                    }
                }

                $monthBalance = &$sections[$sectionKey]['stalls'][$stallKey]['monthly_balances'][$monthIndex];
                $monthBalance['monthly_rate'] = round($monthBalance['monthly_rate'] + $monthlyRate, 2);
                $monthBalance['payment'] = round($monthBalance['payment'] + $paymentsByMonth[$monthIndex], 2);
                $monthBalance['balance'] = round(
                    max(0, $monthBalance['monthly_rate'] - $monthBalance['payment']),
                    2
                );
                unset($monthBalance);
            }
        }

        $vendorMonthlyBalances = array_map(fn ($month) => [
            'month' => $month,
            'monthly_rate' => 0.0,
            'payment' => 0.0,
            'balance' => 0.0,
        ], $monthNames);

        foreach ($sections as &$sectionData) {
            $sectionData['stalls'] = array_values($sectionData['stalls']);
            usort($sectionData['stalls'], fn ($left, $right) => strnatcasecmp(
                (string) $left['stall_number'],
                (string) $right['stall_number']
            ));

            $sectionData['monthly_balances'] = array_map(function ($monthIndex) use (&$sectionData) {
                $monthlyRate = 0.0;
                $payment = 0.0;
                foreach ($sectionData['stalls'] as $stallData) {
                    $stallMonth = $stallData['monthly_balances'][$monthIndex];
                    $monthlyRate += $stallMonth['monthly_rate'];
                    $payment += $stallMonth['payment'];
                }

                return [
                    'month' => $sectionData['stalls'][0]['monthly_balances'][$monthIndex]['month'] ?? null,
                    'monthly_rate' => round($monthlyRate, 2),
                    'payment' => round($payment, 2),
                    'balance' => round(max(0, $monthlyRate - $payment), 2),
                ];
            }, array_keys($monthNames));
            $sectionData['total_balance'] = round(array_sum(array_column($sectionData['monthly_balances'], 'balance')), 2);
        }
        unset($sectionData);

        foreach ($sections as $sectionData) {
            foreach ($sectionData['monthly_balances'] as $monthIndex => $monthBalance) {
                $vendorMonthlyBalances[$monthIndex]['monthly_rate'] += $monthBalance['monthly_rate'];
                $vendorMonthlyBalances[$monthIndex]['payment'] += $monthBalance['payment'];
                $vendorMonthlyBalances[$monthIndex]['balance'] += $monthBalance['balance'];
            }
        }
        foreach ($vendorMonthlyBalances as &$monthBalance) {
            $monthBalance['monthly_rate'] = round($monthBalance['monthly_rate'], 2);
            $monthBalance['payment'] = round($monthBalance['payment'], 2);
            $monthBalance['balance'] = round(
                max(0, $monthBalance['monthly_rate'] - $monthBalance['payment']),
                2
            );
        }
        unset($monthBalance);
        foreach ($vendorMonthlyBalances as $monthIndex => &$monthBalance) {
            ksort($paymentDaysByMonth[$monthIndex]);
            $monthBalance['payment_days'] = array_values(array_map(
                fn ($paymentDay) => [
                    'date' => $paymentDay['date'],
                    'amount' => round($paymentDay['amount'], 2),
                    'or_numbers' => $paymentDay['or_numbers'],
                ],
                $paymentDaysByMonth[$monthIndex]
            ));
        }
        unset($monthBalance);

        $sectionList = array_values($sections);
        usort($sectionList, fn ($left, $right) => strcasecmp($left['section_name'], $right['section_name']));

        return response()->json([
            'year' => $year,
            'vendor' => [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->full_name,
                'total_balance' => round(array_sum(array_column($vendorMonthlyBalances, 'balance')), 2),
                'monthly_balances' => $vendorMonthlyBalances,
                'sections' => $sectionList,
            ],
        ]);
    }
}
