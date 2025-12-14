<?php

namespace App\Models;

use App\Models\Rented;
use App\Models\Stalls;
use App\Models\VendorDetails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StallRemovalRequest extends Model
{
    use HasFactory;


    protected $table = 'stall_removal_request'; // matches your table name

    protected $fillable = [
        'rented_id',
        'vendor_id',
        'stall_id',
        'message',
        'status',
    ];

    /**
     * Relationships
     */

    // Belongs to a rented record
    public function rented()
    {
        return $this->belongsTo(Rented::class, 'rented_id');
    }

    // Belongs to a vendor
    public function vendor()
    {
        return $this->belongsTo(VendorDetails::class, 'vendor_id');
    }

    // Belongs to a stall
    public function stall()
    {
        return $this->belongsTo(Stalls::class, 'stall_id');
    }
}
