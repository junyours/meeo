<?php

namespace App\Services;

use App\Models\Stalls;
use App\Models\StallRateHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StallRateHistoryService
{
    /**
     * Create a new rate history record when stall rates change
     */
    public function createRateHistory($stallId, $dailyRate = null, $monthlyRate = null, $effectiveFromDate = null, $annualRate = null)
    {
        $stall = Stalls::findOrFail($stallId);
        $effectiveFromDate = $effectiveFromDate ?? now()->toDateString();

        \Log::info('createRateHistory called', [
            'stall_id' => $stallId,
            'daily_rate' => $dailyRate,
            'monthly_rate' => $monthlyRate,
            'annual_rate' => $annualRate,
            'effective_from' => $effectiveFromDate
        ]);

        // Check if rates have actually changed
        $currentHistory = $stall->rateHistories()->orderBy('effective_from', 'desc')->first();
        
        \Log::info('Current history check', [
            'current_history_exists' => $currentHistory ? 'yes' : 'no',
            'current_history' => $currentHistory ? [
                'daily_rate' => $currentHistory->daily_rate,
                'monthly_rate' => $currentHistory->monthly_rate,
                'annual_rate' => $currentHistory->annual_rate,
                'effective_from' => $currentHistory->effective_from
            ] : null
        ]);
        
        if ($currentHistory && 
            $currentHistory->daily_rate == $dailyRate && 
            $currentHistory->monthly_rate == $monthlyRate &&
            $currentHistory->annual_rate == $annualRate &&
            $currentHistory->effective_from->format('Y-m-d') == $effectiveFromDate) {
            // No change detected, don't create duplicate history
            \Log::info('Duplicate detected, returning existing history');
            return $currentHistory;
        }

        \Log::info('Creating new rate history record');
        return $stall->createRateHistory($dailyRate, $monthlyRate, $effectiveFromDate, $annualRate);
    }

    /**
     * Get the correct daily rate for a stall for a specific year and month
     */
    public function getDailyRateForMonth($stallId, $year, $month)
    {
        $stall = Stalls::find($stallId);
        if (!$stall) {
            return 0;
        }

        return $stall->getDailyRateForMonth($year, $month) ?? 0;
    }

    /**
     * Get the correct monthly rate for a stall for a specific year and month
     */
    public function getMonthlyRateForMonth($stallId, $year, $month)
    {
        $stall = Stalls::find($stallId);
        if (!$stall) {
            return 0;
        }

        return $stall->getMonthlyRateForMonth($year, $month) ?? 0;
    }

    /**
     * Get correct annual rate for a stall for a specific year and month
     */
    public function getAnnualRateForMonth($stallId, $year, $month)
    {
        $stall = Stalls::find($stallId);
        if (!$stall) {
            return 0;
        }

        return $stall->getAnnualRateForMonth($year, $month) ?? 0;
    }

    /**
     * Calculate monthly rent based on historical rates
     */
    public function calculateMonthlyRentForPeriod($stallId, $year, $month, $daysInMonth = null)
    {
        $dailyRate = $this->getDailyRateForMonth($stallId, $year, $month);
        $monthlyRate = $this->getMonthlyRateForMonth($stallId, $year, $month);
        $annualRate = $this->getAnnualRateForMonth($stallId, $year, $month);
        
        $daysInMonth = $daysInMonth ?? Carbon::createFromDate($year, $month, 1)->daysInMonth;
        
        // Check if stall has annual rate and matches the specific pattern
        if ($annualRate && $annualRate == 40000) {
            // Apply special monthly distribution for 40000 annual rate
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
        
        // If monthly rate is set, use it, otherwise calculate from daily rate
        if ($monthlyRate && $monthlyRate > 0) {
            return $monthlyRate;
        }
        
        return $dailyRate * $daysInMonth;
    }

    /**
     * Calculate prorated monthly rate based on rental start date and historical rates
     */
    public function calculateProratedMonthlyRate($stallId, $rentalStartDate, $targetYear, $targetMonth, $daysInMonth = null)
    {
        $daysInMonth = $daysInMonth ?? Carbon::createFromDate($targetYear, $targetMonth, 1)->daysInMonth;
        $dailyRate = $this->getDailyRateForMonth($stallId, $targetYear, $targetMonth);
        
        // If rental started in the same month and year as target
        if ($rentalStartDate->year == $targetYear && $rentalStartDate->month == $targetMonth) {
            // Calculate days from rental start to end of month
            $daysToCharge = $daysInMonth - $rentalStartDate->day + 1;
            return $dailyRate * $daysToCharge;
        }
        
        // For months after rental start, charge full month
        if ($rentalStartDate->year < $targetYear || 
            ($rentalStartDate->year == $targetYear && $rentalStartDate->month < $targetMonth)) {
            return $dailyRate * $daysInMonth;
        }
        
        // For months before rental start, no charge
        return 0;
    }

    /**
     * Get rate history for a stall
     */
    public function getRateHistory($stallId)
    {
        return Stalls::findOrFail($stallId)
                    ->rateHistories()
                    ->orderBy('effective_from', 'desc')
                    ->get();
    }

    /**
     * Initialize rate history for existing stalls (migration helper)
     */
    public function initializeRateHistoryForStall($stallId)
    {
        $stall = Stalls::findOrFail($stallId);
        
        // Check if history already exists
        $existingHistory = $stall->rateHistories()->count();
        if ($existingHistory > 0) {
            return $stall->rateHistories()->orderBy('effective_from', 'desc')->first();
        }

        // Create initial history record with current rates
        return $stall->createRateHistory(
            $stall->daily_rate,
            $stall->monthly_rate,
            $stall->created_at->format('Y-m-d'),
            $stall->annual_rate
        );
    }

    /**
     * Initialize rate history for all stalls (migration helper)
     */
    public function initializeRateHistoryForAllStalls()
    {
        $stalls = Stalls::all();
        $initializedCount = 0;

        foreach ($stalls as $stall) {
            // Only initialize if no history exists
            if ($stall->rateHistories()->count() === 0) {
                $stall->createRateHistory(
                    $stall->daily_rate,
                    $stall->monthly_rate,
                    $stall->created_at->format('Y-m-d'),
                    $stall->annual_rate
                );
                $initializedCount++;
            }
        }

        return $initializedCount;
    }

    /**
     * Handle stall rate update with history tracking
     */
    public function updateStallRates($stallId, $dailyRate = null, $monthlyRate = null, $effectiveFromDate = null, $annualRate = null)
    {
        return DB::transaction(function () use ($stallId, $dailyRate, $monthlyRate, $effectiveFromDate, $annualRate) {
            $stall = Stalls::findOrFail($stallId);
            
            // Update current rates in stall table
            $stall->update([
                'daily_rate' => $dailyRate,
                'monthly_rate' => $monthlyRate,
                'annual_rate' => $annualRate,
            ]);

            // Create history record
            return $this->createRateHistory($stallId, $dailyRate, $monthlyRate, $effectiveFromDate, $annualRate);
        });
    }
}
