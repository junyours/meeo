<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EventStall extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_id',
        'stall_number',
        'is_ambulant',
        'stall_name',
        'description',
        'size',
        'location',
        'daily_rate',
        'total_days',
        'total_rent',
        'status',
        'row_position',
        'column_position',
        'assigned_vendor_id',
    ];

    protected $casts = [
        'daily_rate' => 'decimal:2',
        'total_rent' => 'decimal:2',
        'is_ambulant' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function activity()
    {
        return $this->belongsTo(EventActivity::class, 'activity_id');
    }

    public function assignedVendor()
    {
        return $this->belongsTo(EventVendor::class, 'assigned_vendor_id');
    }

    public function assignments()
    {
        return $this->hasMany(StallAssignment::class, 'stall_id');
    }

    public function payments()
    {
        return $this->hasMany(Payments::class, 'stall_id');
    }

    public function salesReports()
    {
        return $this->hasMany(ActivitySalesReport::class, 'stall_id');
    }

    public function getCurrentAssignmentAttribute()
    {
        return $this->assignments()->where('status', 'active')->first();
    }

    public function getIsOccupiedAttribute()
    {
        return $this->status === 'occupied' && $this->assigned_vendor_id;
    }

    public function getIsAvailableAttribute()
    {
        return $this->status === 'available';
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeOccupied($query)
    {
        return $query->where('status', 'occupied');
    }

    public function scopeByActivity($query, $activityId)
    {
        return $query->where('activity_id', $activityId);
    }

    public function scopeAmbulant($query)
    {
        return $query->where('is_ambulant', true);
    }

    public function scopeFixed($query)
    {
        return $query->where('is_ambulant', false);
    }

    public function calculateTotalRent()
    {
        $activity = $this->activity;
        if ($activity) {
            $days = $activity->getTotalDaysAttribute();
            $this->total_rent = $this->daily_rate * $days;
            $this->total_days = $days;
            $this->save();
        }
        return $this->total_rent;
    }

    public function assignToVendor($vendorId, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? $this->activity->start_date;
        $endDate = $endDate ?? $this->activity->end_date;

        $this->assigned_vendor_id = $vendorId;
        $this->status = 'occupied';
        $this->save();

        $this->refresh();

        return StallAssignment::create([
            'stall_id' => $this->id,
            'vendor_id' => $vendorId,
            'activity_id' => $this->activity_id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'active',
            'assigned_by' => auth()->id(),
        ]);
    }

    public function releaseStall()
    {
        $this->assigned_vendor_id = null;
        $this->status = 'available';
        $this->save();

        $this->assignments()->where('status', 'active')->update(['status' => 'ended']);
        $this->refresh();
    }
}
