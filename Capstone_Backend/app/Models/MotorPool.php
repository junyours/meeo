<?php

namespace App\Models;

use App\Models\Remittanceable;
use App\Models\InchargeCollector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MotorPool extends Model
{
    use HasFactory;

    protected $table="motorpool";
        protected $fillable = [
        'payment_date',
        'amount',
        'collector_id',
        'status',
    ];

     public function collector()
    {
        return $this->belongsTo(InchargeCollector::class, 'collector_id');
    }

     public function remittanceables()
{
    return $this->morphMany(Remittanceable::class, 'remittable');
}

public function approvedRemittances()
{
    return $this->morphMany(Remittanceable::class, 'remittable')
        ->whereHas('remittance', function($q) {
            $q->where('status', 'approved')
              ->whereNotNull('received_by');
        });
}

}
