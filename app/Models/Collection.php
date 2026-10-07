<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Collection extends Model
{
    use HasFactory;

    protected $fillable = [
        'collection_session_id',
        'vendor_id',
        'rented_id',
        'stall_id',
        'collector_id',
        'amount_to_pay',
        'days_covered',
        'payment_type',
        'is_collected',
        'collected_at',
    ];

    protected $casts = [
        'amount_to_pay' => 'decimal:2',
        'days_covered' => 'integer',
        'is_collected' => 'boolean',
        'collected_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(
            CollectionSession::class,
            'collection_session_id'
        );
    }

    public function vendor()
    {
        return $this->belongsTo(
            VendorDetails::class,
            'vendor_id'
        );
    }

    public function rented()
    {
        return $this->belongsTo(
            Rented::class,
            'rented_id'
        );
    }

    public function stall()
    {
        return $this->belongsTo(
            Stalls::class,
            'stall_id'
        );
    }

    public function collector()
    {
        return $this->belongsTo(
            User::class,
            'collector_id'
        );
    }
}