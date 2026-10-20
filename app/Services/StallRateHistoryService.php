<?php

namespace App\Services;

use App\Models\Stalls;
use App\Models\StallRateHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StallRateHistoryService
{
    private array $rateHistoryCache = [];

    /**
     * Create a new rate history record when stall rates change
     */
    public function createRateHistory($stallId, $dailyRate = null, $monthlyRate = null, $effectiveFromDate = null, $annualRate = null)
    {
        $stall = Stalls::findOrFail($stallId);
        $effectiveFromDate = Carbon::parse($effectiveFromDate ?? now())->toDateString();
        $history = $stall->rateHistories()
            ->whereDate('effective_from', $effectiveFromDate)
            ->orderByDesc('id')
            ->first();

        $rates = [
            'daily_rate' => $dailyRate,
            'monthly_rate' => $monthlyRate,
            'annual_rate' => $annualRate,
        ];

        if ($history && $history->daily_rate == $dailyRate &&
            $history->monthly_rate == $monthlyRate &&
            $history->annual_rate == $annualRate) {
            return $history;
        }

        if ($history) {
            $history->update($rates);
            unset($this->rateHistoryCache[$stallId]);
            return $history->fresh();
        }

        $history = $stall->createRateHistory($dailyRate, $monthlyRate, $effectiveFromDate, $annualRate);
        unset($this->rateHistoryCache[$stallId]);
        return $history;
    }

    /**
     * Get the last recorded rates effective on a specific date.
     */
    public function getRatesForDate($stallId, $date)
    {
        if (!array_key_exists($stallId, $this->rateHistoryCache)) {
            $this->rateHistoryCache[$stallId] = StallRateHistory::where('stall_id', $stallId)
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get();
        }

        $date = Carbon::parse($date)->toDateString();

        return $this->rateHistoryCache[$stallId]
            ->filter(fn ($rate) => $rate->effective_from->toDateString() <= $date)
            ->last();
    }

    public function getDailyRentForRentalDate($rental, $date): float
    {
        return $this->getDailyRentForStallDate(
            $rental->stall,
            $date,
            $rental->daily_rent,
            $rental->monthly_rent
        );
    }

    private function getDailyRentForStallDate($stall, $date, $fallbackDailyRate = null, $fallbackMonthlyRate = null): float
    {
        $section = $stall->section;
        $date = Carbon::parse($date);
        $rate = $this->getRatesForDate($stall->id, $date);
        $dailyRate = $rate?->daily_rate ?? $fallbackDailyRate ?? $stall->daily_rate;
        $monthlyRate = $rate?->monthly_rate ?? $fallbackMonthlyRate ?? $stall->monthly_rate;
        $annualRate = $rate?->annual_rate ?? $stall->annual_rate;
        $daysInMonth = $date->daysInMonth;

        if ($annualRate == 40000) {
            $monthlyRate = in_array($date->month, [1, 2, 4, 5, 7, 8, 10, 11], true) ? 4500 : 1000;
            return $monthlyRate / $daysInMonth;
        }

        $hasDailyRate = $dailyRate > 0;
        $hasMonthlyRate = $monthlyRate > 0;
        if ($hasDailyRate && $hasMonthlyRate) {
            if ($stall->is_monthly ||
                ($stall->stall_number == 16 && strtolower($section->name) === 'meat & fish')) {
                return (float) $monthlyRate / $daysInMonth;
            }

            return (float) $dailyRate;
        }

        if ($section->rate_type === 'fixed') {
            return (float) ($section->monthly_rate ?? 0) / $daysInMonth;
        }

        return (float) ($hasDailyRate ? $dailyRate : ($rental->daily_rent ?? 0));
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
        $stall = Stalls::with('section')->find($stallId);
        if (!$stall) {
            return 0;
        }

        $daysInMonth = $daysInMonth ?? Carbon::createFromDate($year, $month, 1)->daysInMonth;

        $monthlyRent = 0;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::createFromDate($year, $month, $day);
            $monthlyRent += $this->getDailyRentForStallDate($stall, $date);
        }

        return $monthlyRent;
    }

    /**
     * Calculate prorated monthly rate based on rental start date and historical rates
     */
    public function calculateProratedMonthlyRate($stallId, $rentalStartDate, $targetYear, $targetMonth, $daysInMonth = null)
    {
        $daysInMonth = $daysInMonth ?? Carbon::createFromDate($targetYear, $targetMonth, 1)->daysInMonth;
        $stall = Stalls::with('section')->find($stallId);
        if (!$stall) {
            return 0;
        }

        $rentalStartDate = Carbon::parse($rentalStartDate)->startOfDay();
        $monthlyRent = 0;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::createFromDate($targetYear, $targetMonth, $day)->startOfDay();
            if ($date->lessThan($rentalStartDate)) {
                continue;
            }

            $monthlyRent += $this->getDailyRentForStallDate($stall, $date);
        }

        return $monthlyRent;
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
            $effectiveFromDate = Carbon::parse($effectiveFromDate ?? now())->toDateString();
            $this->initializeRateHistoryForStall($stallId);

            if ($effectiveFromDate <= now()->toDateString()) {
                $stall->update([
                    'daily_rate' => $dailyRate,
                    'monthly_rate' => $monthlyRate,
                    'annual_rate' => $annualRate,
                ]);
            }

            return $this->createRateHistory($stallId, $dailyRate, $monthlyRate, $effectiveFromDate, $annualRate);
        });
    }
}
