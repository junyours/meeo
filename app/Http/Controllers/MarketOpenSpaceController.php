<?php

namespace App\Http\Controllers;

use App\Models\Payments;

use Illuminate\Http\Request;

use Carbon\Carbon;

class MarketOpenSpaceController extends Controller
{
    /**
     * Get Market and Open Space Collections
     */
    public function index(Request $request)
    {
        try {
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $year = $request->get('year');
            
            // If year is provided, use it instead of date range
            if ($year) {
                $startDate = Carbon::createFromDate($year, 1, 1)->startOfYear()->format('Y-m-d');
                $endDate = Carbon::createFromDate($year, 12, 31)->endOfYear()->format('Y-m-d');
            } 
            // If no date range provided, get current year
            elseif (!$startDate || !$endDate) {
                $startDate = Carbon::now()->startOfYear()->format('Y-m-d');
                $endDate = Carbon::now()->endOfYear()->format('Y-m-d');
            }

            // Get all payments with relationships
            $payments = Payments::with(['rented.stall.section.area', 'vendor'])
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->whereIn('status', ['paid', 'collected'])
                ->orderBy('payment_date', 'desc')
                ->get();

            // Categorize payments based on area
            $marketPayments = collect();
            $openSpacePayments = collect();
            $tabocGymPayments = collect();

            foreach ($payments as $payment) {
                $areaName = strtolower($payment->rented?->stall?->section?->area?->name ?? '');
                $sectionName = strtolower($payment->rented?->stall?->section?->name ?? '');
                
                // Debug logging - you can remove this later
                // Log::info('Payment categorization:', [
                //     'payment_id' => $payment->id,
                //     'area_name' => $areaName,
                //     'section_name' => $sectionName
                // ]);
                
                // Check if it belongs to Taboc gym (check section name)
                $isTabocGymArea = str_contains($sectionName, 'taboc') || 
                                   str_contains($sectionName, 'gym');

                // Check if it belongs to open space (but exclude Taboc gym)
                $isOpenSpaceArea = (str_contains($areaName, 'open space') || 
                                   str_contains($areaName, 'open') || 
                                   str_contains($areaName, 'space')) && !$isTabocGymArea;

                // Categorize based on area detection
                if ($isTabocGymArea) {
                    $tabocGymPayments->push($payment);
                } elseif ($isOpenSpaceArea) {
                    $openSpacePayments->push($payment);
                } else {
                    $marketPayments->push($payment);
                }
            }

            // Debug: Log categorization results
     
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

            $tabocGymSummary = [
                'total_amount' => $tabocGymPayments->sum('amount'),
                'total_payments' => $tabocGymPayments->count(),
                'unique_vendors' => $tabocGymPayments->pluck('vendor_id')->unique()->count(),
                'payment_types' => $tabocGymPayments->groupBy('payment_type')->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'amount' => $group->sum('amount')
                    ];
                })
            ];

            // Group payments by vendor and date, with latest first
            $groupedMarketPayments = $marketPayments
                ->sortByDesc('payment_date')
                ->groupBy(function ($payment) {
                    return $payment->vendor_id . '_' . $payment->payment_date->format('Y-m-d');
                })
                ->map(function ($group) {
                    $firstPayment = $group->first();
                    return [
                        'id' => $firstPayment->id,
                        'payment_date' => $firstPayment->payment_date->format('Y-m-d'),
                        'vendor_name' => $firstPayment->vendor ? $firstPayment->vendor->first_name . ' ' . $firstPayment->vendor->last_name : 'Unknown',
                        'vendor_id' => $firstPayment->vendor_id,
                        'stall_info' => $group->map(function ($payment) {
                            return [
                                'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                                'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                                'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                                'payment_id' => $payment->id,
                            ];
                        })->toArray(),
                        'payment_types' => $group->pluck('payment_type')->unique()->values()->toArray(),
                        'total_amount' => $group->sum('amount'),
                        'payment_count' => $group->count(),
                        'statuses' => $group->pluck('status')->unique()->values()->toArray(),
                        'all_payments' => $group->map(function ($payment) {
                            return [
                                'id' => $payment->id,
                                'payment_type' => $payment->payment_type,
                                'amount' => $payment->amount,
                                'status' => $payment->status,
                                'missed_days' => $payment->missed_days,
                                'advance_days' => $payment->advance_days,
                                'rented_id' => $payment->rented_id,
                                'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                                'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                                'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                            ];
                        })->toArray(),
                    ];
                })
                ->values()
                ->toArray();

            $groupedOpenSpacePayments = $openSpacePayments
                ->sortByDesc('payment_date')
                ->groupBy(function ($payment) {
                    return $payment->vendor_id . '_' . $payment->payment_date->format('Y-m-d');
                })
                ->map(function ($group) {
                    $firstPayment = $group->first();
                    return [
                        'id' => $firstPayment->id,
                        'payment_date' => $firstPayment->payment_date->format('Y-m-d'),
                        'vendor_name' => $firstPayment->vendor ? $firstPayment->vendor->first_name . ' ' . $firstPayment->vendor->last_name : 'Unknown',
                        'vendor_id' => $firstPayment->vendor_id,
                        'stall_info' => $group->map(function ($payment) {
                            return [
                                'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                                'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                                'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                                'payment_id' => $payment->id,
                            ];
                        })->toArray(),
                        'payment_types' => $group->pluck('payment_type')->unique()->values()->toArray(),
                        'total_amount' => $group->sum('amount'),
                        'payment_count' => $group->count(),
                        'statuses' => $group->pluck('status')->unique()->values()->toArray(),
                        'all_payments' => $group->map(function ($payment) {
                            return [
                                'id' => $payment->id,
                                'payment_type' => $payment->payment_type,
                                'amount' => $payment->amount,
                                'status' => $payment->status,
                                'missed_days' => $payment->missed_days,
                                'advance_days' => $payment->advance_days,
                                'rented_id' => $payment->rented_id,
                                'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                                'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                                'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                            ];
                        })->toArray(),
                    ];
                })
                ->values()
                ->toArray();

            $groupedTabocGymPayments = $tabocGymPayments
                ->sortByDesc('payment_date')
                ->groupBy(function ($payment) {
                    return $payment->vendor_id . '_' . $payment->payment_date->format('Y-m-d');
                })
                ->map(function ($group) {
                    $firstPayment = $group->first();
                    return [
                        'id' => $firstPayment->id,
                        'payment_date' => $firstPayment->payment_date->format('Y-m-d'),
                        'vendor_name' => $firstPayment->vendor ? $firstPayment->vendor->first_name . ' ' . $firstPayment->vendor->last_name : 'Unknown',
                        'vendor_id' => $firstPayment->vendor_id,
                        'stall_info' => $group->map(function ($payment) {
                            return [
                                'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                                'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                                'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                                'payment_id' => $payment->id,
                            ];
                        })->toArray(),
                        'payment_types' => $group->pluck('payment_type')->unique()->values()->toArray(),
                        'total_amount' => $group->sum('amount'),
                        'payment_count' => $group->count(),
                        'statuses' => $group->pluck('status')->unique()->values()->toArray(),
                        'all_payments' => $group->map(function ($payment) {
                            return [
                                'id' => $payment->id,
                                'payment_type' => $payment->payment_type,
                                'amount' => $payment->amount,
                                'status' => $payment->status,
                                'missed_days' => $payment->missed_days,
                                'advance_days' => $payment->advance_days,
                                'rented_id' => $payment->rented_id,
                                'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                                'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                                'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                            ];
                        })->toArray(),
                    ];
                })
                ->values()
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    'market_collections' => [
                        'summary' => $marketSummary,
                        'payments' => $groupedMarketPayments
                    ],
                    'open_space_collections' => [
                        'summary' => $openSpaceSummary,
                        'payments' => $groupedOpenSpacePayments
                    ],
                    'taboc_gym_collections' => [
                        'summary' => $tabocGymSummary,
                        'payments' => $groupedTabocGymPayments
                    ],
                    'total_collections' => [
                        'amount' => $marketSummary['total_amount'] + $openSpaceSummary['total_amount'] + $tabocGymSummary['total_amount'],
                        'payments' => $marketSummary['total_payments'] + $openSpaceSummary['total_payments'] + $tabocGymSummary['total_payments'],
                        'vendors' => $marketSummary['unique_vendors'] + $openSpaceSummary['unique_vendors'] + $tabocGymSummary['unique_vendors']
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

    /**
     * Get Market and Open Space Collections by Year with Monthly Breakdown
     */
    public function getCollectionsByYear(Request $request)
    {
        try {
            $year = $request->get('year', Carbon::now()->year);
            
            $startDate = Carbon::createFromDate($year, 1, 1)->startOfYear();
            $endDate = Carbon::createFromDate($year, 12, 31)->endOfYear();

            // Get all payments with relationships for the year
            $payments = Payments::with(['rented.stall.section.area', 'vendor'])
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->whereIn('status', ['paid', 'collected'])
                ->orderBy('payment_date', 'desc')
                ->get();

            // Initialize monthly data structure
            $monthlyData = [];
            $months = [
                1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
            ];

            foreach ($months as $monthNum => $monthName) {
                $monthlyData[$monthNum] = [
                    'month' => $monthName,
                    'month_number' => $monthNum,
                    'market_amount' => 0,
                    'open_space_amount' => 0,
                    'taboc_gym_amount' => 0,
                    'total_amount' => 0,
                    'market_payments' => collect(),
                    'open_space_payments' => collect(),
                    'taboc_gym_payments' => collect()
                ];
            }

            // Categorize payments by month and area
            foreach ($payments as $payment) {
                $areaName = strtolower($payment->rented?->stall?->section?->area?->name ?? '');
                $sectionName = strtolower($payment->rented?->stall?->section?->name ?? '');
                $monthNum = $payment->payment_date->month;
                
                $isTabocGymArea = str_contains($sectionName, 'taboc') || 
                                   str_contains($sectionName, 'gym');
                
                $isOpenSpaceArea = (str_contains($areaName, 'open space') || 
                                   str_contains($areaName, 'open') || 
                                   str_contains($areaName, 'space')) && !$isTabocGymArea;

                if ($isTabocGymArea) {
                    $monthlyData[$monthNum]['taboc_gym_amount'] += $payment->amount;
                    $monthlyData[$monthNum]['taboc_gym_payments']->push($payment);
                } elseif ($isOpenSpaceArea) {
                    $monthlyData[$monthNum]['open_space_amount'] += $payment->amount;
                    $monthlyData[$monthNum]['open_space_payments']->push($payment);
                } else {
                    $monthlyData[$monthNum]['market_amount'] += $payment->amount;
                    $monthlyData[$monthNum]['market_payments']->push($payment);
                }
                
                $monthlyData[$monthNum]['total_amount'] += $payment->amount;
            }

            // Convert collections to arrays and calculate totals
            $yearlyTotals = [
                'market_amount' => 0,
                'open_space_amount' => 0,
                'taboc_gym_amount' => 0,
                'total_amount' => 0,
                'total_payments' => 0
            ];

            foreach ($monthlyData as $monthNum => &$data) {
                $data['market_payments'] = $this->groupPaymentsByVendorAndDate($data['market_payments']);
                $data['open_space_payments'] = $this->groupPaymentsByVendorAndDate($data['open_space_payments']);
                $data['taboc_gym_payments'] = $this->groupPaymentsByVendorAndDate($data['taboc_gym_payments']);
                $data['market_payment_count'] = $data['market_payments']->count();
                $data['open_space_payment_count'] = $data['open_space_payments']->count();
                $data['taboc_gym_payment_count'] = $data['taboc_gym_payments']->count();
                $data['total_payment_count'] = $data['market_payment_count'] + $data['open_space_payment_count'] + $data['taboc_gym_payment_count'];
                
                $yearlyTotals['market_amount'] += $data['market_amount'];
                $yearlyTotals['open_space_amount'] += $data['open_space_amount'];
                $yearlyTotals['taboc_gym_amount'] += $data['taboc_gym_amount'];
                $yearlyTotals['total_amount'] += $data['total_amount'];
                $yearlyTotals['total_payments'] += $data['total_payment_count'];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'monthly_data' => array_values($monthlyData),
                    'yearly_totals' => $yearlyTotals
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch yearly collections: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get monthly payment details for a specific month
     */
    public function getMonthlyPaymentDetails(Request $request)
    {
        try {
            $year = $request->get('year');
            $month = $request->get('month');
            
            if (!$year || !$month) {
                return response()->json([
                    'success' => false,
                    'message' => 'Year and month are required'
                ], 400);
            }

            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

            // Get all payments for the month
            $payments = Payments::with(['rented.stall.section.area', 'vendor'])
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->whereIn('status', ['paid', 'collected'])
                ->orderBy('payment_date', 'desc')
                ->get();

            // Categorize payments
            $marketPayments = collect();
            $openSpacePayments = collect();
            $tabocGymPayments = collect();

            foreach ($payments as $payment) {
                $areaName = strtolower($payment->rented?->stall?->section?->area?->name ?? '');
                $sectionName = strtolower($payment->rented?->stall?->section?->name ?? '');
                
                $isTabocGymArea = str_contains($sectionName, 'taboc') || 
                                   str_contains($sectionName, 'gym');
                
                $isOpenSpaceArea = (str_contains($areaName, 'open space') || 
                                   str_contains($areaName, 'open') || 
                                   str_contains($areaName, 'space')) && !$isTabocGymArea;

                if ($isTabocGymArea) {
                    $tabocGymPayments->push($payment);
                } elseif ($isOpenSpaceArea) {
                    $openSpacePayments->push($payment);
                } else {
                    $marketPayments->push($payment);
                }
            }

            $groupedMarketPayments = $this->groupPaymentsByVendorAndDate($marketPayments);
            $groupedOpenSpacePayments = $this->groupPaymentsByVendorAndDate($openSpacePayments);
            $groupedTabocGymPayments = $this->groupPaymentsByVendorAndDate($tabocGymPayments);

            return response()->json([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'month' => $month,
                    'month_name' => Carbon::createFromDate($year, $month, 1)->format('F'),
                    'market_collections' => [
                        'summary' => [
                            'total_amount' => $marketPayments->sum('amount'),
                            'total_payments' => $marketPayments->count(),
                            'unique_vendors' => $marketPayments->pluck('vendor_id')->unique()->count()
                        ],
                        'payments' => $groupedMarketPayments->values()->toArray()
                    ],
                    'open_space_collections' => [
                        'summary' => [
                            'total_amount' => $openSpacePayments->sum('amount'),
                            'total_payments' => $openSpacePayments->count(),
                            'unique_vendors' => $openSpacePayments->pluck('vendor_id')->unique()->count()
                        ],
                        'payments' => $groupedOpenSpacePayments->values()->toArray()
                    ],
                    'taboc_gym_collections' => [
                        'summary' => [
                            'total_amount' => $tabocGymPayments->sum('amount'),
                            'total_payments' => $tabocGymPayments->count(),
                            'unique_vendors' => $tabocGymPayments->pluck('vendor_id')->unique()->count()
                        ],
                        'payments' => $groupedTabocGymPayments->values()->toArray()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch monthly payment details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper method to group payments by vendor and date
     */
    private function groupPaymentsByVendorAndDate($payments)
    {
        return $payments
            ->sortByDesc('payment_date')
            ->groupBy(function ($payment) {
                return $payment->vendor_id . '_' . $payment->payment_date->format('Y-m-d');
            })
            ->map(function ($group) {
                $firstPayment = $group->first();
                return [
                    'id' => $firstPayment->id,
                    'payment_date' => $firstPayment->payment_date->format('Y-m-d'),
                    'vendor_name' => $firstPayment->vendor ? $firstPayment->vendor->first_name . ' ' . $firstPayment->vendor->last_name : 'Unknown',
                    'vendor_id' => $firstPayment->vendor_id,
                    'stall_info' => $group->map(function ($payment) {
                        return [
                            'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                            'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                            'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                            'payment_id' => $payment->id,
                        ];
                    })->toArray(),
                    'payment_types' => $group->pluck('payment_type')->unique()->values()->toArray(),
                    'total_amount' => $group->sum('amount'),
                    'payment_count' => $group->count(),
                    'statuses' => $group->pluck('status')->unique()->values()->toArray(),
                    'all_payments' => $group->map(function ($payment) {
                        return [
                            'id' => $payment->id,
                            'payment_type' => $payment->payment_type,
                            'amount' => $payment->amount,
                            'status' => $payment->status,
                            'missed_days' => $payment->missed_days,
                            'advance_days' => $payment->advance_days,
                            'rented_id' => $payment->rented_id,
                            'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                            'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                            'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                        ];
                    })->toArray(),
                ];
            });
    }

    /**
     * Get analytics data for Market & Open Space Collections
     */
    public function getAnalytics(Request $request)
    {
        try {
            $months = $request->get('months', 6); // Default to last 6 months
            
            $monthlyData = [];
            
            for ($i = 0; $i < $months; $i++) {
                $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
                $monthEnd = Carbon::now()->subMonths($i)->endOfMonth();
                
                $payments = Payments::with(['rented.stall.section.area'])
                    ->whereBetween('payment_date', [$monthStart, $monthEnd])
                    ->whereIn('status', ['paid', 'collected'])
                    ->get();

                $marketAmount = 0;
                $openSpaceAmount = 0;
                $tabocGymAmount = 0;

                foreach ($payments as $payment) {
                    $areaName = strtolower($payment->rented?->stall?->section?->area?->name ?? '');
                    $sectionName = strtolower($payment->rented?->stall?->section?->name ?? '');
                    
                    // Debug logging
                    // Log::info('Analytics categorization:', [
                    //     'payment_id' => $payment->id,
                    //     'area_name' => $areaName,
                    //     'section_name' => $sectionName
                    // ]);
                    
                    if (str_contains($sectionName, 'taboc') || str_contains($sectionName, 'gym')) {
                        $tabocGymAmount += $payment->amount;
                    } elseif ((str_contains($areaName, 'open space') || str_contains($areaName, 'open') || str_contains($areaName, 'space')) && !(str_contains($sectionName, 'taboc') || str_contains($sectionName, 'gym'))) {
                        $openSpaceAmount += $payment->amount;
                    } else {
                        $marketAmount += $payment->amount;
                    }
                }

                $monthlyData[] = [
                    'month' => $monthStart->format('M Y'),
                    'market_collections' => $marketAmount,
                    'open_space_collections' => $openSpaceAmount,
                    'taboc_gym_collections' => $tabocGymAmount,
                    'total_collections' => $marketAmount + $openSpaceAmount + $tabocGymAmount
                ];
            }

            return response()->json([
                'success' => true,
                'data' => array_reverse($monthlyData) // Most recent first
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment details for view modal
     */
    public function getPaymentDetails($paymentId)
    {
        try {
            $payment = Payments::with([
                'vendor',
                'rented.stall.section.area',
                'rented.vendor'
            ])->findOrFail($paymentId);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $payment->id,
                    'payment_date' => $payment->payment_date->format('Y-m-d'),
                    'vendor' => [
                        'id' => $payment->vendor?->id,
                        'name' => $payment->vendor ? $payment->vendor->first_name . ' ' . $payment->vendor->last_name : 'Unknown',
                        'contact_number' => $payment->vendor?->contact_number,
                        'address' => $payment->vendor?->address,
                    ],
                    'stall_info' => [
                        'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                        'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                        'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                    ],
                    'payment_details' => [
                        'payment_type' => $payment->payment_type,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                        'missed_days' => $payment->missed_days,
                        'advance_days' => $payment->advance_days,
                    ],
                    'rental_info' => [
                        'monthly_rent' => $payment->rented?->monthly_rent,
                        'daily_rent' => $payment->rented?->daily_rent,
                        'remaining_balance' => $payment->rented?->remaining_balance,
                    ],
                    'created_at' => $payment->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $payment->updated_at->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get multiple payment details for grouped view
     */
    public function getGroupedPaymentDetails(Request $request)
    {
        try {
            $paymentIds = $request->input('payment_ids', []);
            
            if (empty($paymentIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No payment IDs provided'
                ], 400);
            }

            $payments = Payments::with([
                'vendor',
                'rented.stall.section.area',
                'rented.vendor'
            ])->whereIn('id', $paymentIds)->get();

            $paymentDetails = $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'payment_date' => $payment->payment_date->format('Y-m-d'),
                    'vendor' => [
                        'id' => $payment->vendor?->id,
                        'name' => $payment->vendor ? $payment->vendor->first_name . ' ' . $payment->vendor->last_name : 'Unknown',
                        'contact_number' => $payment->vendor?->contact_number,
                        'address' => $payment->vendor?->address,
                    ],
                    'stall_info' => [
                        'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                        'section_name' => $payment->rented?->stall?->section?->name ?? 'N/A',
                        'area_name' => $payment->rented?->stall?->section?->area?->name ?? 'N/A',
                    ],
                    'payment_details' => [
                        'payment_type' => $payment->payment_type,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                        'missed_days' => $payment->missed_days,
                        'advance_days' => $payment->advance_days,
                    ],
                    'rental_info' => [
                        'monthly_rent' => $payment->rented?->monthly_rent,
                        'daily_rent' => $payment->rented?->daily_rent,
                        'remaining_balance' => $payment->rented?->remaining_balance,
                    ],
                    'created_at' => $payment->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $payment->updated_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'payments' => $paymentDetails,
                    'total_amount' => $payments->sum('amount'),
                    'payment_count' => $payments->count(),
                    'vendor' => $payments->first()?->vendor ? [
                        'id' => $payments->first()->vendor->id,
                        'name' => $payments->first()->vendor->first_name . ' ' . $payments->first()->vendor->last_name,
                        'contact_number' => $payments->first()->vendor->contact_number,
                        'address' => $payments->first()->vendor->address,
                    ] : null,
                    'payment_date' => $payments->first()?->payment_date->format('Y-m-d'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch grouped payment details: ' . $e->getMessage()
            ], 500);
        }
    }
}
