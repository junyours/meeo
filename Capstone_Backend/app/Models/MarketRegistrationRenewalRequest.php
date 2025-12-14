<?php

namespace App\Models;

use App\Models\VendorDetails;
use App\Models\MarketRegistration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MarketRegistrationRenewalRequest extends Model
{
    use HasFactory;

       protected $table = 'market_registration_renewal_requests';

    protected $fillable = [
        'registration_id',
        'vendor_id',
        'status',
       
    ];

    public function registration()
    {
        return $this->belongsTo(MarketRegistration::class, 'registration_id');
    }

    public function vendor()
    {
        return $this->belongsTo(VendorDetails::class);
    }
}
