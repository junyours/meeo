<?php

namespace App\Models;

use App\Models\Application;
use App\Models\VendorDetails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StallChangeRequest extends Model
{
    use HasFactory;

       protected $fillable = [
        'application_id',
        'vendor_id',
        'new_stall_ids',
      'old_stall_ids',
        'status',
    ];

    protected $casts = [
               'old_stall_ids' => 'array',
        'new_stall_ids' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function vendor()
    {
        return $this->belongsTo(VendorDetails::class, 'vendor_id');
    }
}
