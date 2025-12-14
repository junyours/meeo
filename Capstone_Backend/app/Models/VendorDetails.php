<?php

namespace App\Models;

use App\Models\User;
use App\Models\Rented;
use App\Models\Application;
use App\Models\MarketRegistration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class VendorDetails extends Model
{
    use HasFactory;

        protected $table = "vendor_details";
protected $fillable = [
    'fullname',
    'age',
    'gender',
    'address',
    'profile_picture',
    'contact_number',
    'emergency_contact',
    'user_id',
    'Business_permit', // now stores file path
    'Sanitary_permit', // now stores file path
    'Dti_permit',      // now stores file path
    'Status',
];


   public function application()
{
    return $this->hasMany(Application::class, 'vendor_id');
}

public function user()
{
    return $this->belongsTo(User::class);
}

public function marketRegistrations()
{
    return $this->hasManyThrough(
        MarketRegistration::class,
        Application::class,
        'vendor_id',        
        'application_id',  
        'id',              
        'id'              
    );
}

}
