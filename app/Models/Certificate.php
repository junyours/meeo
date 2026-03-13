<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Certificate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'certificate_number',
        'template_name',
        'template_fields',
        'vendor_first_name',
        'vendor_middle_name',
        'vendor_last_name',
        'stall_number',
        'issue_date',
        'expiry_date',
        'status',
        'notes',
        'vendor_id',
        'stall_id',
        'issued_by',
    ];

    protected $casts = [
        'template_fields' => 'array',
        'issue_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function vendor()
    {
        return $this->belongsTo(VendorDetails::class, 'vendor_id');
    }

    public function stall()
    {
        return $this->belongsTo(Stalls::class, 'stall_id');
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function getVendorFullNameAttribute()
    {
        return trim("{$this->vendor_first_name} {$this->vendor_middle_name} {$this->vendor_last_name}");
    }

    public function isExpired()
    {
        return $this->expiry_date->isPast();
    }

    public function getDaysUntilExpiryAttribute()
    {
        return $this->expiry_date->diffInDays(now());
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
                    ->orWhere('expiry_date', '<', now());
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->where('expiry_date', '<=', now()->addDays($days))
                    ->where('expiry_date', '>', now())
                    ->where('status', 'active');
    }
}
