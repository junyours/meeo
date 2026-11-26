<?php

namespace App\Models;

use App\Models\Rented;
use App\Models\Sections;
use App\Models\Stalls;
use App\Models\VendorDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorOtherPayment extends Model
{
use HasFactory;

    protected $table = 'vendor_other_payments';

    protected $fillable = [
        'vendor_id',
        'stall_id',
        'section_id',
        'rented_id',
        'payer_name',
        'payer_type',
        'payment_type',
        'or_number',
        'amount',
        'payment_date',
        'status',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function vendor()
    {
        return $this->belongsTo(
            VendorDetails::class,
            'vendor_id'
        );
    }

    public function stall()
    {
        return $this->belongsTo(
            Stalls::class,
            'stall_id'
        );
    }

    public function section()
    {
        return $this->belongsTo(
            Sections::class,
            'section_id'
        );
    }

    public function rented()
    {
        return $this->belongsTo(
            Rented::class,
            'rented_id'
        );
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }
}
