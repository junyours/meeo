<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payments extends Model
{
    use HasFactory;

    protected $fillable = [
        'rented_id',
        'vendor_id',
        'event_vendor_id',
        'payment_type',
        'amount',
        'or_number',
        'payment_date',
        'missed_days',
        'advance_days',
        'status',
        'activity_id',
        'stall_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'missed_days' => 'integer',
        'advance_days' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function rented()
    {
        return $this->belongsTo(Rented::class);
    }

    public function vendor()
    {
        return $this->belongsTo(
            VendorDetails::class,
            'vendor_id'
        );
    }

    public function eventVendor()
    {
        return $this->belongsTo(
            EventVendor::class,
            'event_vendor_id'
        );
    }

    public function activity()
    {
        return $this->belongsTo(
            EventActivity::class,
            'activity_id'
        );
    }

    public function stall()
    {
        return $this->belongsTo(
            EventStall::class,
            'stall_id'
        );
    }
}