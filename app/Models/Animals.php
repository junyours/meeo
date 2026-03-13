<?php

namespace App\Models;

use App\Models\InspectionRecord;
use App\Models\SlaughterPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Animals extends Model
{
    use HasFactory;

          protected $fillable = [
        'animal_type',
        'fixed_rate',
        'ante_mortem_rate',
        'post_mortem_rate',
        'coral_fee_rate',
        'permit_to_slh_rate',
        'slaughter_fee_rate',
        'excess_kilo_limit',

    ];

    
    public function slaughterPayments()
    {
        return $this->hasMany(SlaughterPayment::class, 'animals_id');
    }

    public function inspectionRecords()
{
    return $this->hasMany(InspectionRecord::class, 'animal_id');
}

}
