<?php

namespace App\Models;

use App\Models\Stalls;
use App\Models\Payments;
use App\Models\Collection;
use App\Models\VendorDetails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Rented extends Model
{
    use HasFactory;

    protected $table = 'rented';

    protected $fillable = [
        'vendor_id',
        'stall_id',
        'monthly_rent',
        'daily_rent',
        'last_payment_date',
        'missed_days',
        'next_due_date',
        'status',
        'remaining_balance',
        'updated_at',
    ];

    protected $casts = [
        'monthly_rent' => 'decimal:2',
        'daily_rent' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'last_payment_date' => 'datetime',
        'next_due_date' => 'date',
        'missed_days' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    public function payments()
    {
        return $this->hasMany(
            Payments::class,
            'rented_id'
        );
    }

    public function collections()
    {
        return $this->hasMany(
            Collection::class,
            'rented_id'
        );
    }

  
}