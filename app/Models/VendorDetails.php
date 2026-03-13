<?php

namespace App\Models;

use App\Models\Rented;
use App\Models\Certificate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorDetails extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = "vendor_details";
    
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'contact_number',
        'address',
        'status',
    ];

    // public function application()
    // {
    //     return $this->hasMany(Application::class, 'vendor_id');
    // }

    // public function marketRegistrations()
    // {
    //     return $this->hasManyThrough(
    //         MarketRegistration::class,
    //         Application::class,
    //         'vendor_id',        
    //         'application_id',  
    //         'id',              
    //         'id'              
    //     );
    // }

    public function certificates()
    {
        return $this->hasMany(Certificate::class, 'vendor_id');
    }

    public function activeCertificate()
    {
        return $this->hasOne(Certificate::class, 'vendor_id')
                    ->where('status', 'active')
                    ->latest();
    }

    public function rented()
    {
        return $this->hasMany(Rented::class, 'vendor_id');
    }

    public function getFullNameAttribute()
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function getFirstNameAttribute($value)
    {
        return ucfirst(strtolower($value ?? ''));
    }

    public function getLastNameAttribute($value)
    {
        return ucfirst(strtolower($value ?? ''));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
