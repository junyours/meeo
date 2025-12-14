<?php

namespace App\Models;

use App\Models\Customer;
use App\Models\Remittance;
use App\Models\MeatInspector;
use App\Models\Remittanceable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SlaughterPayment extends Model
{
    use HasFactory;

    protected $table ="slaughter_payment";
       protected $fillable = [
    'animals_id',
    'inspector_id',
    'collector_id',
    'customer_id',
    'slaughter_fee',
    'ante_mortem',
    'post_mortem',
    'coral_fee',
    'permit_to_slh',
    'quantity',
    'total_kilos',
    'per_kilos',
    'total_amount',
    'status',
    'payment_date',
    'is_remitted',
    'remitted_at',
];


     public function animal()
    {
        return $this->belongsTo(Animals::class, 'animals_id');
    }

    public function customer()
{
    return $this->belongsTo(Customer::class, 'customer_id');
}


    public function remittanceables()
{
    return $this->morphMany(Remittanceable::class, 'remittable');
}


    public function collector()
    {
        return $this->belongsTo(InchargeCollector::class, 'collector_id');
    }


   public function inspector()
    {
        return $this->belongsTo(MeatInspector::class, 'inspector_id');
    }

    protected $casts = [
    'per_kilos' => 'array',
];
}
