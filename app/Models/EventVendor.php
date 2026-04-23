<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventVendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'contact_number',
        'address',
        'status',
        'created_by',
    ];

    protected $casts = [
        'status' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stallAssignments()
    {
        return $this->hasMany(StallAssignment::class, 'vendor_id');
    }

    public function eventStalls()
    {
        return $this->hasMany(EventStall::class, 'assigned_vendor_id');
    }

    public function salesReports()
    {
        return $this->hasMany(ActivitySalesReport::class, 'vendor_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    // Accessors
    public function getFullNameAttribute()
    {
        $middleName = $this->middle_name ? " {$this->middle_name}" : '';
        return "{$this->first_name}{$middleName} {$this->last_name}";
    }

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'active' => 'success',
            'inactive' => 'danger',
            default => 'secondary',
        };
    }

    public function getStatusBadgeAttribute()
    {
        $color = $this->status_color;
        $label = ucfirst($this->status);
        return "<span class='badge badge-{$color}'>{$label}</span>";
    }
}
