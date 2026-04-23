<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class EventActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'location',
        'status',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stalls()
    {
        return $this->hasMany(EventStall::class, 'activity_id');
    }

    public function payments()
    {
        return $this->hasMany(Payments::class, 'activity_id');
    }

    public function salesReports()
    {
        return $this->hasMany(ActivitySalesReport::class, 'activity_id');
    }

    public function stallAssignments()
    {
        return $this->hasManyThrough(StallAssignment::class, EventStall::class, 'activity_id', 'stall_id');
    }

    public function getTotalDaysAttribute()
    {
        return $this->start_date && $this->end_date 
            ? $this->start_date->diffInDays($this->end_date) + 1 
            : null;
    }

    public function getIsActiveAttribute()
    {
        return $this->status === 'active' && 
               $this->start_date->lessThanOrEqualTo(now()) && 
               $this->end_date->greaterThanOrEqualTo(now());
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>', now());
    }

    public function scopeCompleted($query)
    {
        return $query->where('end_date', '<', now());
    }

    public function getTotalRevenueAttribute()
    {
        return $this->payments()->where('status', 'paid')->sum('amount');
    }

    public function getTotalStallsAttribute()
    {
        return $this->stalls()->count();
    }

    public function getOccupiedStallsAttribute()
    {
        return $this->stalls()->where('status', 'occupied')->count();
    }

    public function getFormattedStartDateAttribute()
    {
        return $this->start_date ? $this->start_date->format('F,d,Y') : null;
    }

    public function getFormattedEndDateAttribute()
    {
        return $this->end_date ? $this->end_date->format('F,d,Y') : null;
    }

    public function getDurationAttribute()
    {
        return $this->start_date && $this->end_date 
            ? ($this->start_date->diffInDays($this->end_date) + 1) . ' days'
            : null;
    }
}
