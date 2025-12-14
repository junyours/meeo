<?php

namespace App\Models;

use App\Models\Payments;
use App\Models\InchargeCollector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MarketCollection extends Model
{
    use HasFactory;

      protected $fillable = ['payment_id', 'collector_id'];

    public function payment()
    {
        return $this->belongsTo(Payments::class, 'payment_id');
    }

    public function collector()
    {
        return $this->belongsTo(InchargeCollector::class, 'collector_id');
    }
}
