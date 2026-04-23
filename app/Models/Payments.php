<?php

namespace App\Models;

use App\Models\Rented;
use App\Models\Remittance;
use App\Models\Notification;
use App\Models\VendorDetails;
use App\Models\Remittanceable;
use App\Models\InchargeCollector;
use App\Models\EventActivity;
use App\Models\EventStall;
use App\Models\EventVendor;
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
    'payment_date' => 'datetime', // ensures Carbon instance
    'created_at' => 'datetime',   // optional but safe
    'updated_at' => 'datetime',
];

     public function rented()
    {
        return $this->belongsTo(Rented::class);
    }

       public function vendor()
    {
        return $this->belongsTo(VendorDetails::class, 'vendor_id');
    }

    public function eventVendor()
    {
        return $this->belongsTo(EventVendor::class, 'event_vendor_id');
    }

    public function activity()
    {
        return $this->belongsTo(EventActivity::class, 'activity_id');
    }

    public function stall()
    {
        return $this->belongsTo(EventStall::class, 'stall_id');
    }

    
  

    public function remittances()
    {
        return $this->hasManyThrough(
            Remittance::class,
            Remittanceable::class,
            'remittable_id',
            'id',
            'id',
            'remittance_id'
        )->where('remittable_type', self::class);
    }


    public function remittanceables()
{
    return $this->morphMany(Remittanceable::class, 'remittable');
}
   




}
