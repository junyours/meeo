<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StallRateHistory extends Model
{
    use HasFactory;

    protected $table = 'stall_rate_histories';
    
    protected $fillable = [
        'stall_id',
        'daily_rate',
        'monthly_rate',
        'annual_rate',
        'effective_from',
    ];

    protected $casts = [
        'daily_rate' => 'decimal:2',
        'monthly_rate' => 'decimal:2',
        'annual_rate' => 'decimal:2',
        'effective_from' => 'date',
    ];

    /**
     * Get the stall that owns this rate history.
     */
    public function stall()
    {
        return $this->belongsTo(Stalls::class, 'stall_id');
    }

    /**
     * Scope to get rate history effective on or before a specific date
     */
    public function scopeEffectiveOnOrBefore($query, $date)
    {
        return $query->where('effective_from', '<=', $date);
    }

    /**
     * Scope to get the most recent rate for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('effective_from', '<=', $date)
                    ->orderBy('effective_from', 'desc')
                    ->limit(1);
    }

    /**
     * Scope to get rate history for a specific stall
     */
    public function scopeForStall($query, $stallId)
    {
        return $query->where('stall_id', $stallId);
    }

    /**
     * Get the rate that was effective for a specific year and month
     */
    public static function getRateForMonth($stallId, $year, $month)
    {
        $date = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth();
        
        return self::forStall($stallId)
                   ->effectiveOnOrBefore($date)
                   ->orderBy('effective_from', 'desc')
                   ->first();
    }

    /**
     * Get the daily rate for a specific year and month
     */
    public static function getDailyRateForMonth($stallId, $year, $month)
    {
        $rateHistory = self::getRateForMonth($stallId, $year, $month);
        return $rateHistory ? $rateHistory->daily_rate : null;
    }

    /**
     * Get the monthly rate for a specific year and month
     */
    public static function getMonthlyRateForMonth($stallId, $year, $month)
    {
        $rateHistory = self::getRateForMonth($stallId, $year, $month);
        return $rateHistory ? $rateHistory->monthly_rate : null;
    }

    /**
     * Get the annual rate for a specific year and month
     */
    public static function getAnnualRateForMonth($stallId, $year, $month)
    {
        $rateHistory = self::getRateForMonth($stallId, $year, $month);
        return $rateHistory ? $rateHistory->annual_rate : null;
    }
}
