<?php

namespace App\Http\Controllers;

use App\Models\Stalls;
use App\Models\StallRateHistory;
use App\Models\Sections;
use App\Models\Area;
use App\Models\Rented;
use App\Services\StallRateHistoryService;
use Illuminate\Http\Request;

class StallRateHistoryController extends Controller
{
    protected $rateHistoryService;

    public function __construct(StallRateHistoryService $rateHistoryService)
    {
        $this->rateHistoryService = $rateHistoryService;
    }

    /**
     * Get rate history for a specific stall
     */
    public function getStallRateHistory($stallId)
    {
        $stall = Stalls::findOrFail($stallId);
        $history = $this->rateHistoryService->getRateHistory($stallId);

        return response()->json([
            'stall' => $stall->load('section'),
            'rate_history' => $history,
            'current_rates' => [
                'daily_rate' => $stall->daily_rate,
                'monthly_rate' => $stall->monthly_rate,
            ]
        ]);
    }

    /**
     * Get rate for a specific stall, year, and month
     */
    public function getRateForMonth($stallId, $year, $month)
    {
        $stall = Stalls::findOrFail($stallId);
        
        $dailyRate = $this->rateHistoryService->getDailyRateForMonth($stallId, $year, $month);
        $monthlyRate = $this->rateHistoryService->getMonthlyRateForMonth($stallId, $year, $month);
        
        $daysInMonth = \Carbon\Carbon::createFromDate($year, $month, 1)->daysInMonth;
        $calculatedMonthlyRent = $this->rateHistoryService->calculateMonthlyRentForPeriod($stallId, $year, $month, $daysInMonth);

        return response()->json([
            'stall' => $stall->load('section'),
            'year' => $year,
            'month' => $month,
            'days_in_month' => $daysInMonth,
            'historical_daily_rate' => $dailyRate,
            'historical_monthly_rate' => $monthlyRate,
            'calculated_monthly_rent' => $calculatedMonthlyRent,
            'current_daily_rate' => $stall->daily_rate,
            'current_monthly_rate' => $stall->monthly_rate,
        ]);
    }

    /**
     * Demonstrate rate calculation with example scenarios
     */
    public function demonstrateRateCalculation($stallId)
    {
        $stall = Stalls::findOrFail($stallId);
        
        // Example scenarios for different months
        $scenarios = [
            ['year' => 2024, 'month' => 1, 'description' => 'January 2024 (Old Rate: 400/day)'],
            ['year' => 2024, 'month' => 2, 'description' => 'February 2024 (Old Rate: 400/day)'],
            ['year' => 2024, 'month' => 3, 'description' => 'March 2024 (New Rate: 300/day)'],
            ['year' => 2024, 'month' => 4, 'description' => 'April 2024 (New Rate: 300/day)'],
        ];

        $results = [];
        foreach ($scenarios as $scenario) {
            $dailyRate = $this->rateHistoryService->getDailyRateForMonth($stallId, $scenario['year'], $scenario['month']);
            $daysInMonth = \Carbon\Carbon::createFromDate($scenario['year'], $scenario['month'], 1)->daysInMonth;
            $monthlyRent = $this->rateHistoryService->calculateMonthlyRentForPeriod($stallId, $scenario['year'], $scenario['month'], $daysInMonth);

            $results[] = [
                'description' => $scenario['description'],
                'year' => $scenario['year'],
                'month' => $scenario['month'],
                'days_in_month' => $daysInMonth,
                'daily_rate' => $dailyRate,
                'monthly_rent' => $monthlyRent,
                'calculation' => "{$dailyRate} × {$daysInMonth} = {$monthlyRent}"
            ];
        }

        return response()->json([
            'stall' => $stall->load('section'),
            'scenarios' => $results,
            'rate_history' => $this->rateHistoryService->getRateHistory($stallId)
        ]);
    }

    /**
     * Initialize rate history for all stalls (admin function)
     */
    public function initializeAllStalls()
    {
        $initializedCount = $this->rateHistoryService->initializeRateHistoryForAllStalls();
        
        return response()->json([
            'message' => "Rate history initialized for {$initializedCount} stalls.",
            'initialized_count' => $initializedCount
        ]);
    }

    /**
     * Initialize rate history for a specific stall
     */
    public function initializeStall($stallId)
    {
        $rateHistory = $this->rateHistoryService->initializeRateHistoryForStall($stallId);
        
        return response()->json([
            'message' => "Rate history initialized for stall {$stallId}.",
            'rate_history' => $rateHistory
        ]);
    }

    /**
     * Get comprehensive dashboard data for stall rates and availability
     */
    public function getDashboardData()
    {
        try {
            // Get all areas with their sections and stalls
            $areas = Area::with(['sections.stalls' => function($query) {
                $query->with(['currentRental', 'rateHistories' => function($rateQuery) {
                    $rateQuery->orderBy('effective_from', 'desc')->limit(5);
                }]);
            }])->orderBy('sort_order')->get();

            // Process data for dashboard
            $dashboardData = [];
            
            foreach ($areas as $area) {
                $areaData = [
                    'id' => $area->id,
                    'name' => $area->name,
                    'type' => $this->determineAreaType($area->name),
                    'sections' => []
                ];

                foreach ($area->sections as $section) {
                    $sectionData = [
                        'id' => $section->id,
                        'name' => $section->name,
                        'stalls' => [],
                        'availability' => [
                            'total' => 0,
                            'available' => 0,
                            'occupied' => 0
                        ]
                    ];

                    foreach ($section->stalls as $stall) {
                        $stallStatus = $this->getStallStatus($stall);
                        $currentRate = $this->getCurrentRate($stall);
                        $recentRateChanges = $this->getRecentRateChanges($stall);

                        // Calculate rates based on section rate type
                        // First check if stall has individual rates (highest priority)
                        if ($stall->daily_rate || $stall->monthly_rate) {
                            $calculatedRate = [
                                'daily_rate' => number_format($stall->daily_rate, 2),
                                'monthly_rate' => number_format($stall->monthly_rate, 2),
                                'effective_from' => $currentRate['effective_from'] ?? null
                            ];
                        } elseif ($section->rate_type === 'per_sqm' && $stall->size && $section->rate) {
                            // Calculate daily rent: section rate * stall size
                            $dailyRent = $section->rate * $stall->size;
                            $monthlyRent = $dailyRent * 30;
                            
                            $calculatedRate = [
                                'daily_rate' => number_format($dailyRent, 2),
                                'monthly_rate' => number_format($monthlyRent, 2),
                                'effective_from' => $currentRate['effective_from'] ?? null
                            ];
                        } else {
                            // Use existing current_rate for other cases
                            $calculatedRate = $currentRate;
                        }

                        $stallData = [
                            'id' => $stall->id,
                            'stall_number' => $stall->stall_number,
                            'status' => $stallStatus,
                            'current_rate' => $calculatedRate,
                            'rate_changes' => $recentRateChanges,
                            'size' => $stall->size,
                            'position' => [
                                'row' => $stall->row_position,
                                'column' => $stall->column_position
                            ],
                            'tenant' => $stall->currentRental ? [
                                'vendor_id' => $stall->currentRental->vendor_id,
                                'vendor_name' => $stall->currentRental->vendor ? 
                                    $stall->currentRental->vendor->full_name : 'Unknown',
                                'status' => $stall->currentRental->status,
                                'daily_rent' => $stall->currentRental->daily_rent,
                                'monthly_rent' => $stall->currentRental->monthly_rent
                            ] : null,
                            'section_rate_type' => $section->rate_type,
                            'section_rates' => [
                                'daily_rate' => $section->daily_rate,
                                'monthly_rate' => $section->monthly_rate,
                                'rate' => $section->rate
                            ]
                        ];

                        $sectionData['stalls'][] = $stallData;
                        
                        // Update availability counts
                        $sectionData['availability']['total']++;
                        if ($stallStatus === 'available') {
                            $sectionData['availability']['available']++;
                        } elseif ($stallStatus === 'occupied') {
                            $sectionData['availability']['occupied']++;
                        }
                    }

                    $areaData['sections'][] = $sectionData;
                }

                $dashboardData[] = $areaData;
            }

            // Get recent rate changes across all stalls
            $recentRateChanges = StallRateHistory::with(['stall.section.area'])
                ->orderBy('effective_from', 'desc')
                ->limit(20)
                ->get()
                ->map(function($history) {
                    return [
                        'id' => $history->id,
                        'stall' => [
                            'id' => $history->stall->id,
                            'number' => $history->stall->stall_number,
                            'section' => $history->stall->section->name ?? 'Unknown',
                            'area' => $history->stall->section->area->name ?? 'Unknown'
                        ],
                        'daily_rate' => $history->daily_rate,
                        'monthly_rate' => $history->monthly_rate,
                        'effective_from' => $history->effective_from,
                        'created_at' => $history->created_at,
                        'change_type' => $this->determineChangeType($history)
                    ];
                });

            return response()->json([
                'areas' => $dashboardData,
                'recent_rate_changes' => $recentRateChanges,
                'summary' => $this->generateSummary($dashboardData)
            ]);

        } catch (\Exception $e) {
            \Log::error('Dashboard data error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to load dashboard data'], 500);
        }
    }

    /**
     * Determine area type based on name
     */
    private function determineAreaType($areaName)
    {
        $areaName = strtolower($areaName);
        if (strpos($areaName, 'wet') !== false) {
            return 'wet';
        } elseif (strpos($areaName, 'dry') !== false) {
            return 'dry';
        } elseif (strpos($areaName, 'open') !== false) {
            return 'open_space';
        }
        return 'other';
    }

    /**
     * Get stall status
     * Updated: Map 'vacant' to 'available' and remove 'maintenance'
     */
    private function getStallStatus($stall)
    {
        if ($stall->status === 'vacant') {
            return 'available';
        } elseif ($stall->status === 'occupied') {
            return 'occupied';
        }
        
        return 'available'; // Default to available for any other status
    }

    /**
     * Get current rate for stall
     */
    private function getCurrentRate($stall)
    {
        $rateHistory = $stall->rateHistories->first();
        
        return [
            'daily_rate' => $rateHistory ? $rateHistory->daily_rate : $stall->daily_rate,
            'monthly_rate' => $rateHistory ? $rateHistory->monthly_rate : $stall->monthly_rate,
            'effective_from' => $rateHistory ? $rateHistory->effective_from : null
        ];
    }

    /**
     * Get recent rate changes for stall
     */
    private function getRecentRateChanges($stall)
    {
        return $stall->rateHistories->take(3)->map(function($history) {
            return [
                'daily_rate' => $history->daily_rate,
                'monthly_rate' => $history->monthly_rate,
                'effective_from' => $history->effective_from
            ];
        });
    }

    /**
     * Determine the type of rate change
     */
    private function determineChangeType($history)
    {
        $previousHistory = StallRateHistory::where('stall_id', $history->stall_id)
            ->where('effective_from', '<', $history->effective_from)
            ->orderBy('effective_from', 'desc')
            ->first();

        if (!$previousHistory) {
            return 'initial';
        }

        if ($history->daily_rate > $previousHistory->daily_rate || 
            $history->monthly_rate > $previousHistory->monthly_rate) {
            return 'increase';
        } elseif ($history->daily_rate < $previousHistory->daily_rate || 
                  $history->monthly_rate < $previousHistory->monthly_rate) {
            return 'decrease';
        }

        return 'unchanged';
    }

    /**
     * Generate summary statistics
     * Updated: Removed maintenance stalls calculation
     */
    private function generateSummary($dashboardData)
    {
        $totalStalls = 0;
        $availableStalls = 0;
        $occupiedStalls = 0;
        $areasCount = count($dashboardData);

        foreach ($dashboardData as $area) {
            foreach ($area['sections'] as $section) {
                $totalStalls += $section['availability']['total'];
                $availableStalls += $section['availability']['available'];
                $occupiedStalls += $section['availability']['occupied'];
            }
        }

        return [
            'total_areas' => $areasCount,
            'total_stalls' => $totalStalls,
            'available_stalls' => $availableStalls,
            'occupied_stalls' => $occupiedStalls,
            'occupancy_rate' => $totalStalls > 0 ? round(($occupiedStalls / $totalStalls) * 100, 2) : 0
        ];
    }
}
