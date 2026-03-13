<?php

namespace App\Http\Controllers;

use App\Models\Payments;
use App\Models\Rented;
use App\Models\VendorDetails;
use App\Models\Stalls;
use App\Models\Sections;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentReportsController extends Controller
{
    /**
     * Get all payments with related data (consolidated by vendor and date)
     */
    public function getAllPayments()
    {
        try {
            $payments = Payments::with([
                'vendor',
                'rented.stall',
                'rented.vendor'
            ])
            ->orderBy('payment_date', 'desc')
            ->get()
            ->groupBy(function ($payment) {
                return $payment->vendor_id . '_' . $payment->payment_date->format('Y-m-d');
            })
            ->map(function ($groupedPayments) {
                $firstPayment = $groupedPayments->first();
                $totalAmount = $groupedPayments->sum('amount');
                $totalMissedDays = $groupedPayments->sum('missed_days');
                $totalAdvanceDays = $groupedPayments->sum('advance_days');
                $stallCount = $groupedPayments->pluck('rented.stall.stall_number')->unique()->count();
                $paymentIds = $groupedPayments->pluck('id')->toArray();
                $stallNumbers = $groupedPayments->pluck('rented.stall.stall_number')->unique()->toArray();

                return [
                    'id' => $firstPayment->id, // Primary payment ID
                    'payment_ids' => $paymentIds, // All payment IDs for details
                    'payment_date' => $firstPayment->payment_date,
                    'payment_type' => $firstPayment->payment_type,
                    'amount' => $totalAmount,
                    'missed_days' => $totalMissedDays,
                    'advance_days' => $totalAdvanceDays,
                    'status' => $firstPayment->status,
                    'stall_count' => $stallCount,
                    'stall_numbers' => $stallNumbers,
                    'vendor' => $firstPayment->vendor ? [
                        'id' => $firstPayment->vendor->id,
                        'fullname' => $firstPayment->vendor->fullname,
                    ] : null,
                    'rented' => $firstPayment->rented ? [
                        'id' => $firstPayment->rented->id,
                        'stall' => $firstPayment->rented->stall ? [
                            'id' => $firstPayment->rented->stall->id,
                            'stall_number' => $firstPayment->rented->stall->stall_number,
                        ] : null,
                    ] : null,
                    'collector' => null,
                ];
            })->values();

            return response()->json($payments);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payments: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all rentals with related data (consolidated by vendor)
     */
    public function getAllRentals()
    {
        try {
            $rentals = Rented::with([
                'vendor',
                'stall',
                'payments'
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('vendor_id')
            ->map(function ($groupedRentals) {
                $firstRental = $groupedRentals->first();
                $totalDailyRent = $groupedRentals->sum('daily_rent');
                $totalMonthlyRent = $groupedRentals->sum('monthly_rent');
                $totalMissedDays = $groupedRentals->sum('missed_days');
                $totalRemainingBalance = $groupedRentals->sum('remaining_balance');
                $totalPayments = $groupedRentals->pluck('payments')->flatten()->sum('amount');
                $stallCount = $groupedRentals->count();
                $stallNumbers = $groupedRentals->pluck('stall.stall_number')->unique()->toArray();
                $rentalIds = $groupedRentals->pluck('id')->toArray();
                
                // Get the latest payment date
                $lastPaymentDate = $groupedRentals->pluck('last_payment_date')->filter()->max();
                $nextDueDate = $groupedRentals->pluck('next_due_date')->filter()->min();

                return [
                    'id' => $firstRental->id, // Primary rental ID
                    'rental_ids' => $rentalIds, // All rental IDs for details
                    'status' => $firstRental->status,
                    'daily_rent' => $totalDailyRent,
                    'monthly_rent' => $totalMonthlyRent,
                    'missed_days' => $totalMissedDays,
                    'remaining_balance' => $totalRemainingBalance,
                    'last_payment_date' => $lastPaymentDate,
                    'next_due_date' => $nextDueDate,
                    'created_at' => $firstRental->created_at,
                    'stall_count' => $stallCount,
                    'stall_numbers' => $stallNumbers,
                    'vendor' => $firstRental->vendor ? [
                        'id' => $firstRental->vendor->id,
                        'fullname' => $firstRental->vendor->fullname,
                        'contact_number' => $firstRental->vendor->contact_number,
                    ] : null,
                    'stall' => $firstRental->stall ? [
                        'id' => $firstRental->stall->id,
                        'stall_number' => $firstRental->stall->stall_number,
                    ] : null,
                    'payments_count' => $groupedRentals->pluck('payments')->flatten()->count(),
                    'total_payments' => $totalPayments,
                ];
            })->values();

            return response()->json($rentals);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rentals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment statistics
     */
    public function getPaymentStats()
    {
        try {
            $totalPayments = Payments::count();
            $totalAmount = Payments::sum('amount');
            $totalRemainingBalance = Rented::sum('remaining_balance');

            // Payment type distribution
            $paymentTypes = Payments::select('payment_type', DB::raw('count(*) as count'))
                ->groupBy('payment_type')
                ->pluck('count', 'payment_type')
                ->toArray();

            // Status distribution
            $statusDistribution = Rented::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Monthly payment trends
            $monthlyTrends = Payments::select(
                    DB::raw('DATE_FORMAT(payment_date, "%Y-%m") as month'),
                    DB::raw('SUM(amount) as total'),
                    DB::raw('COUNT(*) as count')
                )
                ->where('payment_date', '>=', now()->subYear())
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            return response()->json([
                'totalPayments' => $totalPayments,
                'totalAmount' => $totalAmount,
                'totalRemainingBalance' => $totalRemainingBalance,
                'paymentTypes' => $paymentTypes,
                'statusDistribution' => $statusDistribution,
                'monthlyTrends' => $monthlyTrends,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed payment information for multiple payments
     */
    public function getPaymentDetails($ids)
    {
        try {
            $paymentIds = explode(',', $ids);
            $payments = Payments::with([
                'vendor',
                'rented.stall',
                'rented.vendor',
                'remittances'
            ])
            ->whereIn('id', $paymentIds)
            ->get();

            $detailedPayments = $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'payment_date' => $payment->payment_date,
                    'payment_type' => $payment->payment_type,
                    'amount' => $payment->amount,
                    'missed_days' => $payment->missed_days,
                    'advance_days' => $payment->advance_days,
                    'status' => $payment->status,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                    'vendor' => $payment->vendor,
                    'rented' => $payment->rented,
                    'collector' => null,
                    'remittances' => $payment->remittances,
                ];
            });

            return response()->json([
                'payments' => $detailedPayments,
                'total_amount' => $payments->sum('amount'),
                'total_missed_days' => $payments->sum('missed_days'),
                'total_advance_days' => $payments->sum('advance_days'),
                'vendor' => $payments->first()->vendor,
                'payment_count' => $payments->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get detailed rental information for multiple rentals
     */
    public function getRentalDetails($ids)
    {
        try {
            $rentalIds = explode(',', $ids);
            $rentals = Rented::with([
                'vendor',
                'stall',
                'payments'
            ])
            ->whereIn('id', $rentalIds)
            ->get();

            $detailedRentals = $rentals->map(function ($rental) {
                return [
                    'id' => $rental->id,
                    'status' => $rental->status,
                    'daily_rent' => $rental->daily_rent,
                    'monthly_rent' => $rental->monthly_rent,
                    'missed_days' => $rental->missed_days,
                    'remaining_balance' => $rental->remaining_balance,
                    'last_payment_date' => $rental->last_payment_date,
                    'next_due_date' => $rental->next_due_date,
                    'created_at' => $rental->created_at,
                    'updated_at' => $rental->updated_at,
                    'vendor' => $rental->vendor,
                    'stall' => $rental->stall,
                    'payments' => $rental->payments,
                ];
            });

            return response()->json([
                'rentals' => $detailedRentals,
                'total_daily_rent' => $rentals->sum('daily_rent'),
                'total_monthly_rent' => $rentals->sum('monthly_rent'),
                'total_missed_days' => $rentals->sum('missed_days'),
                'total_remaining_balance' => $rentals->sum('remaining_balance'),
                'total_payments' => $rentals->pluck('payments')->flatten()->sum('amount'),
                'vendor' => $rentals->first()->vendor,
                'rental_count' => $rentals->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rental not found: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get filtered payments
     */
    public function getFilteredPayments(Request $request)
    {
        try {
            $query = Payments::with([
                'vendor',
                'rented.stall',
                'rented.vendor'
            ]);

            // Date range filter
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('payment_date', [
                    $request->start_date,
                    $request->end_date
                ]);
            }

            // Payment type filter
            if ($request->has('payment_type') && $request->payment_type !== 'all') {
                $query->where('payment_type', $request->payment_type);
            }

            // Vendor filter
            if ($request->has('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            // Stall filter
            if ($request->has('stall_id')) {
                $query->whereHas('rented.stall', function($q) use ($request) {
                    $q->where('id', $request->stall_id);
                });
            }

            $payments = $query->orderBy('payment_date', 'desc')->get();

            return response()->json($payments);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch filtered payments: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get filtered rentals
     */
    public function getFilteredRentals(Request $request)
    {
        try {
            $query = Rented::with([
                'vendor',
                'stall',
                'payments'
            ]);

            // Status filter
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Vendor filter
            if ($request->has('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            // Stall filter
            if ($request->has('stall_id')) {
                $query->where('stall_id', $request->stall_id);
            }

            // Balance range filter
            if ($request->has('min_balance')) {
                $query->where('remaining_balance', '>=', $request->min_balance);
            }
            if ($request->has('max_balance')) {
                $query->where('remaining_balance', '<=', $request->max_balance);
            }

            $rentals = $query->orderBy('created_at', 'desc')->get();

            return response()->json($rentals);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch filtered rentals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Market and Open Space Collections
     */
    public function getMarketOpenSpaceCollections(Request $request)
    {
        try {
            $startDate = $request->get('start_date', Carbon::today()->format('Y-m-d'));
            $endDate = $request->get('end_date', Carbon::today()->format('Y-m-d'));

            // Get all payments with relationships
            $payments = Payments::with(['rented.stall.section.area', 'vendor'])
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->where('status', 'paid')
                ->get();

            // Categorize payments based on area
            $marketPayments = collect();
            $openSpacePayments = collect();

            foreach ($payments as $payment) {
                $areaName = strtolower($payment->rented?->stall?->section?->area?->name ?? '');
                
                // Check if it belongs to wet/dry area (Market)
                $isMarketArea = str_contains($areaName, 'wet') || 
                               str_contains($areaName, 'dry') || 
                               str_contains($areaName, 'market');

                // Check if it belongs to open space
                $isOpenSpaceArea = str_contains($areaName, 'open space') || 
                                   str_contains($areaName, 'open') || 
                                   str_contains($areaName, 'space');

                if ($isOpenSpaceArea) {
                    $openSpacePayments->push($payment);
                } else {
                    $marketPayments->push($payment);
                }
            }

            // Calculate summaries
            $marketSummary = [
                'total_amount' => $marketPayments->sum('amount'),
                'total_payments' => $marketPayments->count(),
                'unique_vendors' => $marketPayments->pluck('vendor_id')->unique()->count(),
                'payment_types' => $marketPayments->groupBy('payment_type')->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'amount' => $group->sum('amount')
                    ];
                })
            ];

            $openSpaceSummary = [
                'total_amount' => $openSpacePayments->sum('amount'),
                'total_payments' => $openSpacePayments->count(),
                'unique_vendors' => $openSpacePayments->pluck('vendor_id')->unique()->count(),
                'payment_types' => $openSpacePayments->groupBy('payment_type')->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'amount' => $group->sum('amount')
                    ];
                })
            ];

            // Prepare detailed data
            $marketPaymentsData = $marketPayments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_type' => $payment->payment_type,
                    'payment_date' => $payment->payment_date->format('Y-m-d'),
                    'vendor_name' => $payment->vendor ? $payment->vendor->first_name . ' ' . $payment->vendor->last_name : 'Unknown',
                    'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                    'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                    'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                ];
            });

            $openSpacePaymentsData = $openSpacePayments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_type' => $payment->payment_type,
                    'payment_date' => $payment->payment_date->format('Y-m-d'),
                    'vendor_name' => $payment->vendor ? $payment->vendor->first_name . ' ' . $payment->vendor->last_name : 'Unknown',
                    'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                    'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                    'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    'market_collections' => [
                        'summary' => $marketSummary,
                        'payments' => $marketPaymentsData
                    ],
                    'open_space_collections' => [
                        'summary' => $openSpaceSummary,
                        'payments' => $openSpacePaymentsData
                    ],
                    'total_collections' => [
                        'amount' => $marketSummary['total_amount'] + $openSpaceSummary['total_amount'],
                        'payments' => $marketSummary['total_payments'] + $openSpaceSummary['total_payments'],
                        'vendors' => $marketSummary['unique_vendors'] + $openSpaceSummary['unique_vendors']
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch collections data: ' . $e->getMessage()
            ], 500);
        }
    }
}
