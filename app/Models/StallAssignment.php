<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class StallAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'stall_id',
        'vendor_id',
        'activity_id',
        'start_date',
        'end_date',
        'status',
        'assigned_by',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function stall()
    {
        return $this->belongsTo(EventStall::class, 'stall_id');
    }

    public function vendor()
    {
        return $this->belongsTo(\App\Models\EventVendor::class, 'vendor_id');
    }

    public function activity()
    {
        return $this->belongsTo(EventActivity::class, 'activity_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function payments()
    {
        return $this->hasMany(Payments::class, 'assignment_id');
    }

    public function salesReports()
    {
        return $this->hasMany(ActivitySalesReport::class, 'assignment_id');
    }

    public function getDurationDaysAttribute()
    {
        if ($this->start_date && $this->end_date) {
            return $this->start_date->diffInDays($this->end_date) + 1;
        }
        return 0;
    }

    public function getIsActiveAttribute()
    {
        return $this->status === 'active' && 
               $this->start_date->lessThanOrEqualTo(now()) && 
               $this->end_date->greaterThanOrEqualTo(now());
    }

    public function getIsExpiredAttribute()
    {
        return $this->end_date->lessThan(now());
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByActivity($query, $activityId)
    {
        return $query->where('activity_id', $activityId);
    }

    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function getTotalPaymentsAttribute()
    {
        return $this->payments()->where('status', 'paid')->sum('amount');
    }

    public function getTotalSalesAttribute()
    {
        return $this->salesReports()->sum('total_sales');
    }

    public function extendAssignment($newEndDate)
    {
        $this->end_date = $newEndDate;
        $this->save();
        
        return $this;
    }

    public function terminateAssignment($reason = null)
    {
        $this->status = 'terminated';
        $this->end_date = now();
        if ($reason) {
            $this->notes = ($this->notes ? $this->notes . "\n" : "") . "Terminated: " . $reason;
        }
        $this->save();
        
        // Update stall status
        if ($this->stall) {
            $this->stall->releaseStall();
        }
        
        return $this;
    }
}
