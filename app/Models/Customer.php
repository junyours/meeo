<?php

namespace App\Models;

use App\Models\Animals;
use App\Models\InspectionRecord;
use App\Models\SlaughterPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory;


    protected $table="customer_details";
    protected $fillable = [
        'user_id',
        'fullname',
        'age',
        'gender',
        'contact_number',
        'emergency_contact',
        'address',
        'profile_picture',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inspectionRecords()
{
    return $this->hasMany(InspectionRecord::class, 'customer_id');
}

    public function animals()
    {
        return $this->hasManyThrough(
            Animals::class,        // Target model
            SlaughterPayment::class, // Intermediate model
            'customer_id',         // Foreign key on SlaughterPayment
            'id',                  // Foreign key on Animals
            'id',                  // Local key on Customer
            'animals_id'           // Local key on SlaughterPayment pointing to Animals
        );
    }
}
