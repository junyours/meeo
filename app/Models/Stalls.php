<?php

namespace App\Models;

use App\Models\Rented;
use App\Models\Sections;
use App\Models\Application;
use App\Models\StallStatusLogs;
use App\Models\VendorDetails;
use App\Models\StallRateHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Stalls extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stall';
     protected $fillable = [
        'section_id', 'stall_number', 'row_position', 'column_position', 
        'size', 'status','is_active','message','pending_removal','sort_order',
        'daily_rate', 'monthly_rate', 'annual_rate', 'is_monthly'
    ];

        protected $casts = [
        'is_active' => 'boolean',
        'pending_removal' => 'boolean',
        'is_monthly' => 'boolean',
    ];
    public function section()
    {
        return $this->belongsTo(Sections::class,'section_id');
    }

    public function currentRental()
    {
        return $this->hasOne(Rented::class, 'stall_id')
            ->whereIn('status', ['active', 'occupied', 'advance', 'temp_closed', 'partial', 'fully paid']);
    }

    public function rented()
    {
        return $this->hasone(Rented::class, 'stall_id');  
        // or ->hasOne() if a stall can be rented only once
    }

    public function rentals()
{
    return $this->hasMany(Rented::class, 'stall_id');
}

 public function applications()
{
    return $this->belongsToMany(Application::class, 'rented', 'stall_id', 'application_id');
}

  public function statusLogs()
    {
        return $this->hasMany(StallStatusLogs::class, 'stall_id')
                    ->orderBy('created_at', 'desc');
    }

    /**
     * Get the rate history for this stall
     */
    public function rateHistories()
    {
        return $this->hasMany(StallRateHistory::class, 'stall_id')
                    ->orderBy('effective_from', 'desc');
    }

    /**
     * Get the current rate (most recent) for this stall
     */
    public function currentRate()
    {
        return $this->hasOne(StallRateHistory::class, 'stall_id')
                    ->orderBy('effective_from', 'desc');
    }

    /**
     * Get the daily rate for a specific year and month
     */
    public function getDailyRateForMonth($year, $month)
    {
        // First check if there's a historical rate
        $historicalRate = StallRateHistory::getDailyRateForMonth($this->id, $year, $month);
        
        if ($historicalRate !== null) {
            return $historicalRate;
        }

        // Fall back to current stall rate
        return $this->daily_rate;
    }

    /**
     * Get the monthly rate for a specific year and month
     */
    public function getMonthlyRateForMonth($year, $month)
    {
        // First check if there's a historical rate
        $historicalRate = StallRateHistory::getMonthlyRateForMonth($this->id, $year, $month);
        
        if ($historicalRate !== null) {
            return $historicalRate;
        }

        // Fall back to current stall rate
        return $this->monthly_rate;
    }

    /**
     * Get the annual rate for a specific year and month
     */
    public function getAnnualRateForMonth($year, $month)
    {
        // First check if there's a historical rate
        $historicalRate = StallRateHistory::getAnnualRateForMonth($this->id, $year, $month);
        
        if ($historicalRate !== null) {
            return $historicalRate;
        }

        // Fall back to current stall rate
        return $this->annual_rate;
    }

    /**
     * Create a new rate history record
     */
    public function createRateHistory($dailyRate = null, $monthlyRate = null, $effectiveFromDate = null, $annualRate = null)
    {
        $effectiveFromDate = $effectiveFromDate ?? now()->toDateString();
        
        \Log::info('Stalls.createRateHistory called', [
            'stall_id' => $this->id,
            'daily_rate' => $dailyRate,
            'monthly_rate' => $monthlyRate,
            'annual_rate' => $annualRate,
            'effective_from' => $effectiveFromDate
        ]);
        
        try {
            $rateHistory = $this->rateHistories()->create([
                'daily_rate' => $dailyRate,
                'monthly_rate' => $monthlyRate,
                'annual_rate' => $annualRate,
                'effective_from' => $effectiveFromDate,
            ]);
            
            \Log::info('Rate history created successfully in database', [
                'rate_history_id' => $rateHistory->id,
                'stall_id' => $rateHistory->stall_id
            ]);
            
            return $rateHistory;
        } catch (\Exception $e) {
            \Log::error('Failed to create rate history in database', [
                'error' => $e->getMessage(),
                'stall_id' => $this->id,
                'daily_rate' => $dailyRate,
                'monthly_rate' => $monthlyRate,
                'annual_rate' => $annualRate,
                'effective_from' => $effectiveFromDate,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

}
