<?php

namespace App\Http\Controllers;

use App\Models\VendorDetails;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Sections;
use App\Models\Payments;
use App\Services\StallRateHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorAnalysisController extends Controller
{
    protected $rateHistoryService;

    public function __construct(StallRateHistoryService $rateHistoryService)
    {
        $this->rateHistoryService = $rateHistoryService;
    }

    /**
     * Calculate prorated monthly rate based on rental start date and month using historical rates
     */
    private function calculateProratedMonthlyRate($stallId, $rentalStartDate, $targetYear, $targetMonth, $daysInMonth)
    {
        return $this->rateHistoryService->calculateProratedMonthlyRate(
            $stallId, 
            $rentalStartDate, 
            $targetYear, 
            $targetMonth, 
            $daysInMonth
        );
    }
    public function getVendors()
    {
        $vendors = VendorDetails::select('id', 'first_name', 'middle_name', 'last_name')
            ->where('status', 'active')
            ->orderBy('last_name')
            ->get()
            ->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'fullname' => $vendor->full_name
                ];
            });

        return response()->json($vendors);
    }

    public function getVendorAnalysis($vendorId, Request $request)
    {
        $vendor = VendorDetails::findOrFail($vendorId);
        
        // Get the year from request, default to current year
        $targetYear = $request->input('year', now()->year);
        
        // Get the section filter from request (optional)
        $sectionFilter = $request->input('section', null);
        
        // Get all rented stalls for this vendor that were active during target year (for monthly breakdown)
        $rentedStalls = Rented::with(['stall.section', 'payments'])
            ->where('vendor_id', $vendorId)
            ->where(function($query) use ($targetYear) {
                // Include currently active rentals
                $query->whereIn('status', ['active', 'occupied', 'advance', 'temp_closed', 'partial', 'fully paid'])
                      // OR rentals that were active during the target year but are now unoccupied
                      ->orWhere(function($subQuery) use ($targetYear) {
                          $subQuery->where('status', 'unoccupied')
                                   ->whereYear('created_at', '<=', $targetYear)
                                   ->where(function($dateQuery) use ($targetYear) {
                                       // Either rental was created in the target year or was updated to unoccupied in the target year
                                       $dateQuery->whereYear('created_at', $targetYear)
                                                ->orWhereYear('updated_at', $targetYear);
                                   });
                      });
            })
            ->get();

        // Get only currently active rentals for summary statistics
        $currentlyActiveRentedStalls = Rented::with(['stall.section'])
            ->where('vendor_id', $vendorId)
            ->whereIn('status', ['active', 'occupied', 'advance', 'temp_closed', 'partial', 'fully paid'])
            ->get();

        $stallCount = $currentlyActiveRentedStalls->count();
        $totalDaily = 0;
        $totalSpaceRights = 0;

        foreach ($currentlyActiveRentedStalls as $rental) {
            $section = $rental->stall->section;
            $stall = $rental->stall;
            $dailyRent = $rental->daily_rent;
            
            // Check if stall is marked as monthly and has monthly rate
            if ($stall->is_monthly && $stall->monthly_rate) {
                // Use monthly rate for daily display when is_monthly is true
                $dailyRent = $stall->monthly_rate;
            }
            
            $totalDaily += $dailyRent;

            // Sum space rights numeric values with better error handling
            $rightsType = $section->rights_type ?? 'none';
            
            if ($rightsType === 'stall_right') {
                $stallRights = floatval($section->stall_rights ?? 0);
                $totalSpaceRights += $stallRights;
            } elseif ($rightsType === 'space_right') {
                $spaceRights = floatval($section->space_right ?? 0);
                $totalSpaceRights += $spaceRights;
            } elseif ($rightsType === 'both') {
                $stallRights = floatval($section->stall_rights ?? 0);
                $spaceRights = floatval($section->space_right ?? 0);
                $totalSpaceRights += $stallRights;
                $totalSpaceRights += $spaceRights;
            }
        }

       

        // Calculate rates based on totals using currently active rentals
        $totalMonthly = 0;
        $totalAnnual = 0;
        $hasAnyAnnualRate = false;
        
        foreach ($currentlyActiveRentedStalls as $rental) {
            $section = $rental->stall->section;
            $stall = $rental->stall;
            
            // Use historical rates for current month calculation
            $currentMonth = now()->month;
            $currentYear = now()->year;
            
            // Check if stall has its own daily, monthly, and annual rates in history
            $historicalDailyRate = $this->rateHistoryService->getDailyRateForMonth($stall->id, $currentYear, $currentMonth);
            $historicalMonthlyRate = $this->rateHistoryService->getMonthlyRateForMonth($stall->id, $currentYear, $currentMonth);
            $historicalAnnualRate = $this->rateHistoryService->getAnnualRateForMonth($stall->id, $currentYear, $currentMonth);
            
            $hasStallDailyRate = !is_null($historicalDailyRate) && $historicalDailyRate > 0;
            $hasStallMonthlyRate = !is_null($historicalMonthlyRate) && $historicalMonthlyRate > 0;
            $hasStallAnnualRate = !is_null($historicalAnnualRate) && $historicalAnnualRate > 0;
            
            // Add annual rate if available
            if ($hasStallAnnualRate) {
                $totalAnnual += $historicalAnnualRate;
                $hasAnyAnnualRate = true;
            }
            
            if ($hasStallDailyRate && $hasStallMonthlyRate) {
                // Check if stall is marked as monthly
                if ($stall->is_monthly) {
                    // For monthly stalls, use monthly rate directly (not multiplied by 30)
                    $totalMonthly += $historicalMonthlyRate;
                } elseif ($stall->stall_number == 16 && strtolower($section->name) === 'meat & fish') {
                    // Use monthly rate directly for stall 16 in meat section (not multiplied by 30)
                    $totalMonthly += $historicalMonthlyRate;
                } else {
                    // Use historical stall-specific daily rate with days in month calculation
                    $totalMonthly += $historicalDailyRate * 30; // Keep 30 for standardization
                }
            } elseif ($section->rate_type === 'fixed') {
                // Use section fixed rate
                $totalMonthly += floatval($section->monthly_rate ?? 0);
            } else {
                // Use historical daily rate calculation
                $dailyRateToUse = $hasStallDailyRate ? $historicalDailyRate : $rental->daily_rent;
                $totalMonthly += $dailyRateToUse * 30;
            }
        }
        
        // If no annual rates were found, calculate from monthly
        if (!$hasAnyAnnualRate) {
            $totalAnnual = $totalMonthly * 12;
        }

        // Create aggregated vendor data with 2 decimal places
        $vendorAggregatedData = [
            'vendor_name' => $vendor->full_name,
            'stall_count' => $stallCount,
            'daily' => (float) number_format($totalDaily, 2, '.', ''),
            'daily_display' => $this->getDailyDisplayText($currentlyActiveRentedStalls),
            'monthly' => (float) number_format($totalMonthly, 2, '.', ''),
            'annual' => (float) number_format($totalAnnual, 2, '.', ''),
            'space_rights' => (float) number_format($totalSpaceRights, 2, '.', '')
        ];

        // Get monthly payments for the specified year
        $monthlyPayments = $this->getMonthlyPayments($vendorId, $targetYear);
        
        // Get section-specific payment breakdown
        $sectionBreakdown = $this->getSectionBreakdown($vendorId, $targetYear);
        
        // Get detailed payment information for monthly analysis
        $monthlyPaymentDetails = $this->getMonthlyPaymentDetails($vendorId, $targetYear, $sectionFilter);
        
        // Calculate monthly balances
        $monthlyBalances = [];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        
        // Check for leap year and adjust February days
        $isLeapYear = ($targetYear % 4 == 0 && ($targetYear % 100 != 0 || $targetYear % 400 == 0));
        $daysInMonths = [31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        
        foreach ($months as $index => $month) {
            $payment = $monthlyPayments[$index] ?? 0;
            
            // Calculate monthly rate using day-by-day approach for accurate proration
            $monthlyRateForMonth = 0;
            $targetMonth = $index + 1;
            
            // Create an array to hold daily rates for each day of the month
            $dailyRates = [];
            for ($day = 1; $day <= $daysInMonths[$index]; $day++) {
                $dailyRates[$day] = 0;
            }
            
            // Calculate the daily rate for each day based on active stalls
            foreach ($rentedStalls as $rental) {
                $section = $rental->stall->section;
                $stall = $rental->stall;
                
                // Check if this rental was active during the target month
                $rentalStart = $rental->created_at->copy()->startOfDay();
                $monthStart = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, 1)->startOfDay();
                $monthEnd = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, $daysInMonths[$index])->endOfDay();
                
                // Check if rental became unoccupied during this month or before
                $rentalEnd = null;
                if ($rental->status === 'unoccupied' && $rental->updated_at) {
                    $rentalEnd = $rental->updated_at->copy()->endOfDay();
                }
                
                // Skip if rental wasn't active during this month at all
                if ($rentalStart->greaterThan($monthEnd) || 
                    ($rentalEnd && $rentalEnd->lessThan($monthStart))) {
                    continue;
                }
                
                // Get historical rates for this specific month
                $historicalDailyRate = $this->rateHistoryService->getDailyRateForMonth($stall->id, $targetYear, $targetMonth);
                $historicalMonthlyRate = $this->rateHistoryService->getMonthlyRateForMonth($stall->id, $targetYear, $targetMonth);
                $historicalAnnualRate = $this->rateHistoryService->getAnnualRateForMonth($stall->id, $targetYear, $targetMonth);
                
                $hasStallDailyRate = !is_null($historicalDailyRate) && $historicalDailyRate > 0;
                $hasStallMonthlyRate = !is_null($historicalMonthlyRate) && $historicalMonthlyRate > 0;
                $hasStallAnnualRate = !is_null($historicalAnnualRate) && $historicalAnnualRate > 0;
                
                // Determine the daily rate to use for this stall
                $dailyRateToUse = 0;
                
                // Check if stall has annual rate and matches the specific annual rate pattern
                if ($hasStallAnnualRate && $historicalAnnualRate == 40000) {
                    // Apply special monthly distribution for 40000 annual rate
                    $monthlyRateForAnnualStall = $this->getMonthlyRateForAnnualStall($targetMonth);
                    $dailyRateToUse = $monthlyRateForAnnualStall / $daysInMonths[$index];
                } elseif ($hasStallDailyRate && $hasStallMonthlyRate) {
                    // Check if stall is marked as monthly
                    if ($stall->is_monthly) {
                        // For monthly stalls, use monthly rate directly
                        $dailyRateToUse = $historicalMonthlyRate / $daysInMonths[$index];
                    } elseif ($stall->stall_number == 16 && strtolower($section->name) === 'meat & fish') {
                        // Special logic for stall number 16 in meat section - use monthly rate directly
                        $dailyRateToUse = $historicalMonthlyRate / $daysInMonths[$index];
                    } else {
                        // Use historical stall-specific daily rate
                        $dailyRateToUse = $historicalDailyRate;
                    }
                } elseif ($section->rate_type === 'fixed') {
                    // Use section fixed rate converted to daily
                    $dailyRateToUse = floatval($section->monthly_rate ?? 0) / $daysInMonths[$index];
                } else {
                    // Use historical daily rate or rental daily rent
                    $dailyRateToUse = $hasStallDailyRate ? $historicalDailyRate : $rental->daily_rent;
                }
                
                // Add this stall's daily rate to each day it was active
                for ($day = 1; $day <= $daysInMonths[$index]; $day++) {
                    $currentDay = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, $day)->startOfDay();
                    
                    // Check if this stall is active on this specific day
                    $isStallActiveOnDay = true;
                    
                    // If rental starts after this day, it's not active
                    if ($rentalStart->greaterThan($currentDay)) {
                        $isStallActiveOnDay = false;
                    }
                    
                    // If rental ended before this day, it's not active
                    if ($rentalEnd && $rentalEnd->lessThan($currentDay)) {
                        $isStallActiveOnDay = false;
                    }
                    
                    // Add the daily rate if the stall is active on this day
                    if ($isStallActiveOnDay) {
                        $dailyRates[$day] += $dailyRateToUse;
                    }
                }
            }
            
            // Sum up all daily rates to get the monthly rate
            $monthlyRateForMonth = array_sum($dailyRates);
            
            \Log::info('Calculated monthly rate using day-by-day approach', [
                'vendor_id' => $vendorId,
                'month' => $month,
                'target_year' => $targetYear,
                'days_in_month' => $daysInMonths[$index],
                'daily_rates_sum' => $monthlyRateForMonth,
                'daily_rates_breakdown' => $dailyRates
            ]);
            
            // Calculate deposit (excess payment)
            $deposit = $payment > $monthlyRateForMonth ? $payment - $monthlyRateForMonth : 0;
            
            // If there's a deposit, the balance should be 0, otherwise calculate normally
            $balance = $deposit > 0 ? 0 : $monthlyRateForMonth - $payment;
            
            // Format to 2 decimal places and handle negative zero
            $formattedPayment = number_format($payment, 2, '.', '');
            $formattedMonthlyRate = number_format($monthlyRateForMonth, 2, '.', '');
            $formattedBalance = number_format($balance, 2, '.', '');
            $formattedDeposit = number_format($deposit, 2, '.', '');
            
            // Convert -0.00 to 0.00
            if (abs($formattedBalance) < 0.01) {
                $formattedBalance = '0.00';
            }
            
            $monthlyBalances[] = [
                'month' => $month,
                'payment' => (float) $formattedPayment,
                'balance' => (float) $formattedBalance,
                'deposit' => (float) $formattedDeposit,
                'monthly_rate' => (float) $formattedMonthlyRate // Include the dynamic monthly rate for this specific month
            ];
        }

        $response = [
            'vendor' => [
                'id' => $vendor->id,
                'fullname' => $vendor->full_name
            ],
            'vendor_analysis' => $vendorAggregatedData,
            'totals' => [
                'daily' => (float) number_format($totalDaily, 2, '.', ''),
                'monthly' => (float) number_format($totalMonthly, 2, '.', ''), // Keep standard 30-day rate for card display
                'annual' => (float) number_format($totalAnnual, 2, '.', '')
            ],
            'monthly_breakdown' => $monthlyBalances,
            'monthly_payment_details' => $monthlyPaymentDetails,
            'section_breakdown' => $sectionBreakdown,
            'yearly_totals' => [
                'total_payments' => (float) number_format(array_sum($monthlyPayments), 2, '.', ''),
                'total_balance' => (float) number_format(array_sum(array_column($monthlyBalances, 'balance')), 2, '.', '') // Use dynamic balances
            ]
        ];

        return response()->json($response);
    }

    private function getMonthlyPayments($vendorId, $year = null)
    {
        $targetYear = $year ?? now()->year;
        
        $payments = Payments::where('vendor_id', $vendorId)
            ->whereYear('payment_date', $targetYear)
            ->whereIn('status', ['paid', 'collected'])
            ->get()
            ->groupBy(function ($payment) {
                return $payment->payment_date->format('n') - 1; // 0-based month index
            });

        $monthlyPayments = [];
        for ($i = 0; $i < 12; $i++) {
            $monthlyPayments[$i] = $payments->get($i, collect())->sum('amount');
        }

        return $monthlyPayments;
    }

    private function getSectionBreakdown($vendorId, $year = null)
    {
        $targetYear = $year ?? now()->year;
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        
        // Check for leap year and adjust February days
        $isLeapYear = ($targetYear % 4 == 0 && ($targetYear % 100 != 0 || $targetYear % 400 == 0));
        $daysInMonths = [31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        
        // Get all rented stalls for this vendor with their sections and payments
        $rentedStalls = Rented::with(['stall.section', 'payments'])
            ->where('vendor_id', $vendorId)
            ->where(function($query) use ($targetYear) {
                // Include currently active rentals
                $query->whereIn('status', ['active', 'occupied', 'advance', 'temp_closed', 'partial', 'fully paid'])
                      // OR rentals that were active during the target year but are now unoccupied
                      ->orWhere(function($subQuery) use ($targetYear) {
                          $subQuery->where('status', 'unoccupied')
                                   ->whereYear('created_at', '<=', $targetYear)
                                   ->where(function($dateQuery) use ($targetYear) {
                                       // Either the rental was created in the target year or was updated to unoccupied in the target year
                                       $dateQuery->whereYear('created_at', $targetYear)
                                                ->orWhereYear('updated_at', $targetYear);
                                   });
                      });
            })
            ->get();

        // Group by section
        $sectionsData = [];
        
        // First, calculate summary statistics using only currently active rentals
        $currentlyActiveRentedStallsForSections = Rented::with(['stall.section'])
            ->where('vendor_id', $vendorId)
            ->whereIn('status', ['active', 'occupied', 'advance', 'temp_closed', 'partial', 'fully paid'])
            ->get();
        
        foreach ($currentlyActiveRentedStallsForSections as $rental) {
            $section = $rental->stall->section;
            $sectionId = $section->id;
            $sectionName = $section->name;
            
            // Initialize section if not exists
            if (!isset($sectionsData[$sectionId])) {
                $sectionsData[$sectionId] = [
                    'section_id' => $sectionId,
                    'section_name' => $sectionName,
                    'stall_count' => 0,
                    'daily_total' => 0,
                    'daily_display' => '',
                    'monthly_total' => 0,
                    'space_rights' => 0,
                    'monthly_breakdown' => []
                ];
            }
            
            // Update section totals for summary statistics
            $sectionsData[$sectionId]['stall_count']++;
            
            // Check if stall is marked as monthly and has monthly rate
            $dailyRentForSection = $rental->daily_rent;
            if ($rental->stall->is_monthly && $rental->stall->monthly_rate) {
                // Use monthly rate for daily display when is_monthly is true
                $dailyRentForSection = $rental->stall->monthly_rate;
                // Set display text to "Month" for sections with monthly stalls
                $sectionsData[$sectionId]['daily_display'] = 'Month';
            }
            
            $sectionsData[$sectionId]['daily_total'] += $dailyRentForSection;
            
            // Calculate space rights for this section
            $rightsType = $section->rights_type ?? 'none';
            if ($rightsType === 'stall_right') {
                $sectionsData[$sectionId]['space_rights'] += floatval($section->stall_rights ?? 0);
            } elseif ($rightsType === 'space_right') {
                $sectionsData[$sectionId]['space_rights'] += floatval($section->space_right ?? 0);
            } elseif ($rightsType === 'both') {
                $sectionsData[$sectionId]['space_rights'] += floatval($section->stall_rights ?? 0) + floatval($section->space_right ?? 0);
            }
        }
        
        // Calculate monthly breakdown for each section
        foreach ($sectionsData as $sectionId => &$sectionData) {
            // Get all rentals in this section to calculate monthly total using historical rates
            $sectionMonthlyTotal = 0;
            foreach ($currentlyActiveRentedStallsForSections as $rental) {
                if ($rental->stall->section->id == $sectionId) {
                    $section = $rental->stall->section;
                    $stall = $rental->stall;
                    
                    // Get historical rates for current month
                    $currentMonth = now()->month;
                    $currentYear = now()->year;
                    
                    // Check if stall has its own daily and monthly rates in history
                    $historicalDailyRate = $this->rateHistoryService->getDailyRateForMonth($stall->id, $currentYear, $currentMonth);
                    $historicalMonthlyRate = $this->rateHistoryService->getMonthlyRateForMonth($stall->id, $currentYear, $currentMonth);
                    
                    $hasStallDailyRate = !is_null($historicalDailyRate) && $historicalDailyRate > 0;
                    $hasStallMonthlyRate = !is_null($historicalMonthlyRate) && $historicalMonthlyRate > 0;
                    
                    if ($hasStallDailyRate && $hasStallMonthlyRate) {
                        // Check if stall is marked as monthly
                        if ($stall->is_monthly) {
                            // For monthly stalls, use monthly rate directly (not multiplied by 30)
                            $sectionMonthlyTotal += $historicalMonthlyRate;
                        } elseif ($stall->stall_number == 16 && strtolower($section->name) === 'meat & fish') {
                            // Use monthly rate directly for stall 16 in meat section (not multiplied by 30)
                            $sectionMonthlyTotal += $historicalMonthlyRate;
                        } else {
                            // Use historical stall-specific daily rate with days in month calculation
                            $sectionMonthlyTotal += $historicalDailyRate * 30; // Keep 30 for standardization
                        }
                    } elseif ($section->rate_type === 'fixed') {
                        // Use section fixed rate
                        $sectionMonthlyTotal += floatval($section->monthly_rate ?? 0);
                    } else {
                        // Use historical daily rate calculation
                        $dailyRateToUse = $hasStallDailyRate ? $historicalDailyRate : $rental->daily_rent;
                        $sectionMonthlyTotal += $dailyRateToUse * 30;
                    }
                }
            }
            $sectionData['monthly_total'] = $sectionMonthlyTotal;
            
            // Get all payments for this section
            $sectionMonthlyPayments = [];
            for ($i = 0; $i < 12; $i++) {
                $sectionMonthlyPayments[$i] = 0;
            }
            
            // Sum payments across all rentals in this section
            foreach ($rentedStalls as $rental) {
                if ($rental->stall->section->id == $sectionId) {
                    $rentalPayments = $rental->payments()
                        ->whereYear('payment_date', $targetYear)
                        ->whereIn('status', ['paid', 'collected'])
                        ->get()
                        ->groupBy(function ($payment) {
                            return $payment->payment_date->format('n') - 1;
                        });
                    
                    for ($i = 0; $i < 12; $i++) {
                        $sectionMonthlyPayments[$i] += $rentalPayments->get($i, collect())->sum('amount');
                    }
                }
            }
            
            // Create monthly breakdown for this section using day-by-day approach
            foreach ($months as $index => $month) {
                $payment = $sectionMonthlyPayments[$index] ?? 0;
                
                // Calculate monthly rate using day-by-day approach for accurate proration
                $monthlyRateForMonth = 0;
                $targetMonth = $index + 1;
                
                // Create an array to hold daily rates for each day of the month
                $dailyRates = [];
                for ($day = 1; $day <= $daysInMonths[$index]; $day++) {
                    $dailyRates[$day] = 0;
                }
                
                // Calculate the daily rate for each day based on active stalls in this section
                foreach ($rentedStalls as $rental) {
                    if ($rental->stall->section->id == $sectionId) {
                        $section = $rental->stall->section;
                        $stall = $rental->stall;
                        
                        // Check if this rental was active during the target month
                        $rentalStart = $rental->created_at->copy()->startOfDay();
                        $monthStart = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, 1)->startOfDay();
                        $monthEnd = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, $daysInMonths[$index])->endOfDay();
                        
                        // Check if rental became unoccupied during this month or before
                        $rentalEnd = null;
                        if ($rental->status === 'unoccupied' && $rental->updated_at) {
                            $rentalEnd = $rental->updated_at->copy()->endOfDay();
                        }
                        
                        // Skip if rental wasn't active during this month at all
                        if ($rentalStart->greaterThan($monthEnd) || 
                            ($rentalEnd && $rentalEnd->lessThan($monthStart))) {
                            continue;
                        }
                        
                        // Get historical rates for this specific month
                        $historicalDailyRate = $this->rateHistoryService->getDailyRateForMonth($stall->id, $targetYear, $targetMonth);
                        $historicalMonthlyRate = $this->rateHistoryService->getMonthlyRateForMonth($stall->id, $targetYear, $targetMonth);
                        
                        $hasStallDailyRate = !is_null($historicalDailyRate) && $historicalDailyRate > 0;
                        $hasStallMonthlyRate = !is_null($historicalMonthlyRate) && $historicalMonthlyRate > 0;
                        
                        // Determine the daily rate to use for this stall
                        $dailyRateToUse = 0;
                        if ($hasStallDailyRate && $hasStallMonthlyRate) {
                            // Check if stall is marked as monthly
                            if ($stall->is_monthly) {
                                // For monthly stalls, use monthly rate directly
                                $dailyRateToUse = $historicalMonthlyRate / $daysInMonths[$index];
                            } elseif ($stall->stall_number == 16 && strtolower($section->name) === 'meat & fish') {
                                // For stall 16, calculate daily equivalent from monthly rate
                                $dailyRateToUse = $historicalMonthlyRate / $daysInMonths[$index];
                            } else {
                                // Use historical stall-specific daily rate
                                $dailyRateToUse = $historicalDailyRate;
                            }
                        } elseif ($section->rate_type === 'fixed') {
                            // Use section fixed rate converted to daily
                            $dailyRateToUse = floatval($section->monthly_rate ?? 0) / $daysInMonths[$index];
                        } else {
                            // Use historical daily rate or rental daily rent
                            $dailyRateToUse = $hasStallDailyRate ? $historicalDailyRate : $rental->daily_rent;
                        }
                        
                        // Add this stall's daily rate to each day it was active
                        for ($day = 1; $day <= $daysInMonths[$index]; $day++) {
                            $currentDay = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, $day)->startOfDay();
                            
                            // Check if this stall is active on this specific day
                            $isStallActiveOnDay = true;
                            
                            // If rental starts after this day, it's not active
                            if ($rentalStart->greaterThan($currentDay)) {
                                $isStallActiveOnDay = false;
                            }
                            
                            // If rental ended before this day, it's not active
                            if ($rentalEnd && $rentalEnd->lessThan($currentDay)) {
                                $isStallActiveOnDay = false;
                            }
                            
                            // Add the daily rate if the stall is active on this day
                            if ($isStallActiveOnDay) {
                                $dailyRates[$day] += $dailyRateToUse;
                            }
                        }
                    }
                }
                
                // Sum up all daily rates to get the monthly rate
                $monthlyRateForMonth = array_sum($dailyRates);
                
                $balance = $monthlyRateForMonth - $payment;
                
                // Calculate deposit (excess payment)
                $deposit = $payment > $monthlyRateForMonth ? $payment - $monthlyRateForMonth : 0;
                
                // If there's a deposit, the balance should be 0, otherwise calculate normally
                $balance = $deposit > 0 ? 0 : $monthlyRateForMonth - $payment;
                
                // Format to 2 decimal places and handle negative zero
                $formattedPayment = number_format($payment, 2, '.', '');
                $formattedMonthlyRate = number_format($monthlyRateForMonth, 2, '.', '');
                $formattedBalance = number_format($balance, 2, '.', '');
                $formattedDeposit = number_format($deposit, 2, '.', '');
                
                // Convert -0.00 to 0.00
                if (abs($formattedBalance) < 0.01) {
                    $formattedBalance = '0.00';
                }
                
                $sectionData['monthly_breakdown'][] = [
                    'month' => $month,
                    'payment' => (float) $formattedPayment,
                    'balance' => (float) $formattedBalance,
                    'deposit' => (float) $formattedDeposit,
                    'monthly_rate' => (float) $formattedMonthlyRate
                ];
            }
            
            // Format totals
            $sectionData['daily_total'] = (float) number_format($sectionData['daily_total'], 2, '.', '');
            $sectionData['monthly_total'] = (float) number_format($sectionData['monthly_total'], 2, '.', '');
            $sectionData['space_rights'] = (float) number_format($sectionData['space_rights'], 2, '.', '');
        }
        
        // Convert to array and sort by section name
        $sectionsArray = array_values($sectionsData);
        usort($sectionsArray, function ($a, $b) {
            return strcmp($a['section_name'], $b['section_name']);
        });
        
        return $sectionsArray;
    }

    /**
     * Get display text for daily rate based on monthly stalls
     */
    private function getDailyDisplayText($rentedStalls)
    {
        $hasMonthlyStalls = false;
        
        foreach ($rentedStalls as $rental) {
            if ($rental->stall->is_monthly) {
                $hasMonthlyStalls = true;
                break;
            }
        }
        
        return $hasMonthlyStalls ? 'Month' : '';
    }

    /**
     * Add or update OR numbers for payments on a specific date
     */
    public function updateOrNumbersForDate(Request $request)
    {
        $request->validate([
            'vendor_id' => 'required|integer|exists:vendor_details,id',
            'payment_date' => 'required|date',
            'or_number' => 'required|string|max:255',
            'section' => 'nullable|integer' // Optional section filter
        ]);

        $vendorId = $request->input('vendor_id');
        $paymentDate = $request->input('payment_date');
        $newOrNumber = trim($request->input('or_number'));
        $sectionFilter = $request->input('section');

        // Get all payments for the vendor on the specified date
        $paymentsQuery = Payments::where('vendor_id', $vendorId)
            ->whereDate('payment_date', $paymentDate)
            ->whereIn('status', ['paid', 'collected']);
            
        // If section filter is provided, filter payments by section
        if ($sectionFilter && $sectionFilter !== 'all') {
            $paymentsQuery->whereHas('rented', function($query) use ($sectionFilter) {
                $query->whereHas('stall', function($stallQuery) use ($sectionFilter) {
                    $stallQuery->where('section_id', $sectionFilter);
                });
            });
        }
        
        $payments = $paymentsQuery->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot add OR number: No payments have been made for this date. OR numbers can only be added for dates with existing payments.'
            ], 404);
        }

        // Get existing OR numbers for this date
        $existingOrNumbers = $payments->pluck('or_number')->filter()->unique()->toArray();

        // Combine existing OR numbers with new one using '/' separator
        if (!empty($existingOrNumbers)) {
            // Remove any existing OR numbers that are the same as the new one
            $existingOrNumbers = array_filter($existingOrNumbers, function($or) use ($newOrNumber) {
                return trim($or) !== $newOrNumber;
            });

            if (!empty($existingOrNumbers)) {
                $combinedOrNumber = implode(' / ', array_merge($existingOrNumbers, [$newOrNumber]));
            } else {
                $combinedOrNumber = $newOrNumber;
            }
        } else {
            $combinedOrNumber = $newOrNumber;
        }

        // Update all payments for this date with the combined OR number
        $updateQuery = Payments::where('vendor_id', $vendorId)
            ->whereDate('payment_date', $paymentDate)
            ->whereIn('status', ['paid', 'collected']);
            
        // If section filter is provided, filter updates by section
        if ($sectionFilter && $sectionFilter !== 'all') {
            $updateQuery->whereHas('rented', function($query) use ($sectionFilter) {
                $query->whereHas('stall', function($stallQuery) use ($sectionFilter) {
                    $stallQuery->where('section_id', $sectionFilter);
                });
            });
        }
        
        $updatedCount = $updateQuery->update(['or_number' => $combinedOrNumber]);

        return response()->json([
            'success' => true,
            'message' => "Successfully updated OR number for {$updatedCount} payment(s).",
            'updated_payments' => $updatedCount,
            'or_number' => $combinedOrNumber,
            'payment_date' => $paymentDate
        ]);
    }

    /**
     * Get OR numbers for a specific payment date
     */
    public function getOrNumbersForDate(Request $request)
    {
        $request->validate([
            'vendor_id' => 'required|integer|exists:vendor_details,id',
            'payment_date' => 'required|date'
        ]);

        $vendorId = $request->input('vendor_id');
        $paymentDate = $request->input('payment_date');

        $payments = Payments::where('vendor_id', $vendorId)
            ->whereDate('payment_date', $paymentDate)
            ->whereIn('status', ['paid', 'collected'])
            ->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payments found for the specified date. OR numbers can only be retrieved for dates with existing payments.'
            ], 404);
        }

        $orNumbers = $payments->pluck('or_number')->filter()->unique()->toArray();

        return response()->json([
            'success' => true,
            'or_numbers' => $orNumbers,
            'payment_count' => $payments->count(),
            'payment_date' => $paymentDate
        ]);
    }

    private function getMonthlyPaymentDetails($vendorId, $year = null, $sectionFilter = null)
    {
        $targetYear = $year ?? now()->year;
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        
        // Get all payments for the vendor in the specified year
        $paymentsQuery = Payments::where('vendor_id', $vendorId)
            ->whereYear('payment_date', $targetYear)
            ->whereIn('status', ['paid', 'collected']);
            
        // If section filter is provided, join with rented table to filter by section
        if ($sectionFilter && $sectionFilter !== 'all') {
            $paymentsQuery->whereHas('rented', function($query) use ($sectionFilter) {
                $query->whereHas('stall', function($stallQuery) use ($sectionFilter) {
                    $stallQuery->where('section_id', $sectionFilter);
                });
            });
        }
        
        $payments = $paymentsQuery->orderBy('payment_date')->get();

        // Group payments by date and combine OR numbers
        $groupedPayments = [];
        
        foreach ($payments as $payment) {
            $date = $payment->payment_date->format('Y-m-d');
            $month = $payment->payment_date->format('n') - 1; // 0-based month index
            $monthName = $months[$month];
            
            if (!isset($groupedPayments[$date])) {
                $groupedPayments[$date] = [
                    'date' => $date,
                    'month' => $monthName,
                    'or_numbers' => [],
                    'total_amount' => 0
                ];
            }
            
            // Add OR number if it exists and is not already added
            $orNumber = $payment->or_number ?? null;
            if ($orNumber && !in_array($orNumber, $groupedPayments[$date]['or_numbers'])) {
                $groupedPayments[$date]['or_numbers'][] = $orNumber;
            }
            
            // Sum the amount
            $groupedPayments[$date]['total_amount'] += $payment->amount;
        }

        // Group payments by month and create the structure for the table
        $monthlyDetails = [];
        
        foreach ($months as $monthIndex => $monthName) {
            $monthPayments = array_filter($groupedPayments, function($payment) use ($monthName) {
                return $payment['month'] === $monthName;
            });

            $monthlyDetails[$monthName] = [
                'payments' => array_map(function($payment, $index) {
                    // Combine OR numbers with '/' separator
                    $combinedOrNumber = !empty($payment['or_numbers']) ? implode(' / ', $payment['or_numbers']) : '-';
                    
                    return [
                        'no' => $index + 1,
                        'date' => $payment['date'],
                        'or_no' => $combinedOrNumber,
                        'amount' => (float) number_format($payment['total_amount'], 2, '.', '')
                    ];
                }, array_values($monthPayments), array_keys(array_values($monthPayments)))
            ];
        }

        return $monthlyDetails;
    }

    /**
     * Get monthly rate for annual stall based on specific distribution pattern
     * For annual rate of 40000:
     * - Jan, Feb: 4500 each
     * - Mar: 1000
     * - Apr, May: 4500 each
     * - Jun: 1000
     * - Jul, Aug: 4500 each
     * - Sep: 1000
     * - Oct, Nov: 4500 each
     * - Dec: 1000
     */
    private function getMonthlyRateForAnnualStall($month)
    {
        // Define the monthly distribution pattern
        $monthlyDistribution = [
            1 => 4500,  // January
            2 => 4500,  // February
            3 => 1000,  // March
            4 => 4500,  // April
            5 => 4500,  // May
            6 => 1000,  // June
            7 => 4500,  // July
            8 => 4500,  // August
            9 => 1000,  // September
            10 => 4500, // October
            11 => 4500, // November
            12 => 1000, // December
        ];

        return $monthlyDistribution[$month] ?? 0;
    }
}
