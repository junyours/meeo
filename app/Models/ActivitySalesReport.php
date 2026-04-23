<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ActivitySalesReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_id',
        'stall_id',
        'vendor_id',
        'report_date',
        'day_number',
        'total_sales',
        'products',
    ];

    protected $casts = [
        'report_date' => 'datetime',
        'total_sales' => 'decimal:2',
        'products' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function activity()
    {
        return $this->belongsTo(EventActivity::class, 'activity_id');
    }

    public function stall()
    {
        return $this->belongsTo(EventStall::class, 'stall_id');
    }

    public function assignment()
    {
        return $this->belongsTo(StallAssignment::class, 'assignment_id');
    }

    public function vendor()
    {
        return $this->belongsTo(VendorDetails::class, 'vendor_id');
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeByActivity($query, $activityId)
    {
        return $query->where('activity_id', $activityId);
    }

    public function scopeByStall($query, $stallId)
    {
        return $query->where('stall_id', $stallId);
    }

    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeVerified($query)
    {
        return $query->where('verified', true);
    }

    public function scopeUnverified($query)
    {
        return $query->where('verified', false);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('report_date', [$startDate, $endDate]);
    }

    public function getTotalSalesForActivity($activityId)
    {
        return self::byActivity($activityId)->sum('total_sales');
    }

    public function getTotalSalesForStall($stallId)
    {
        return self::byStall($stallId)->sum('total_sales');
    }

    public function getTotalSalesForVendor($vendorId)
    {
        return self::byVendor($vendorId)->sum('total_sales');
    }

    public function getAverageDailySalesForStall($stallId)
    {
        $totalSales = $this->getTotalSalesForStall($stallId);
        $reportCount = self::byStall($stallId)->count();
        
        return $reportCount > 0 ? $totalSales / $reportCount : 0;
    }

    public function verify($verifiedBy)
    {
        $this->verified = true;
        $this->verified_by = $verifiedBy;
        $this->verified_at = now();
        $this->save();
        
        return $this;
    }

    public function unverify()
    {
        $this->verified = false;
        $this->verified_by = null;
        $this->verified_at = null;
        $this->save();
        
        return $this;
    }

    public function getSalesBreakdownAttribute()
    {
        return [
            'cash_sales' => $this->cash_sales,
            'credit_sales' => $this->credit_sales,
            'other_sales' => $this->other_sales,
            'total_sales' => $this->total_sales,
        ];
    }

    public function getRankingInActivity($activityId)
    {
        $salesData = self::byActivity($activityId)
            ->selectRaw('stall_id, SUM(total_sales) as total_sales')
            ->groupBy('stall_id')
            ->orderBy('total_sales', 'desc')
            ->get()
            ->pluck('stall_id')
            ->toArray();

        return array_search($this->stall_id, $salesData) + 1;
    }
}
