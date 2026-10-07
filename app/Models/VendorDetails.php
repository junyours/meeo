<?php

namespace App\Models;


use App\Models\Collection;
use App\Models\Rented;
use App\Models\VendorQrCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

   public function collections()
{
    return $this->hasMany(
        Collection::class,
        'vendor_id'
    );
}

public function payments()
{
    return $this->hasMany(
        Payments::class,
        'vendor_id'
    );
}

public function qrCode()
{
    return $this->hasOne(
        VendorQrCode::class,
        'vendor_id'
    );
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
