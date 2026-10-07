<?php

namespace App\Models;

use App\Models\VendorDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorQrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'qr_token',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

     public function vendor()
    {
        return $this->belongsTo(
            VendorDetails::class,
            'vendor_id'
        );
    }
}