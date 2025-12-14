<?php

namespace App\Models;


use App\Models\Stalls;
use App\Models\Sections;
use App\Models\VendorDetails;
use App\Models\MarketRegistration;
use App\Models\StallChangeRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Application extends Model
{
    use HasFactory;
    protected $casts = [
        'stall_ids' => 'array', // ✅ Laravel will auto-cast JSON <-> array
    ];


    protected $table ="applications";
      protected $fillable = [
        'vendor_id',
        'section_id',
        'stall_ids',
        'business_name',
        'payment_type',
        'status',
        'letter_of_intent'
    ];

  public function vendor()
    {
        return $this->belongsTo(VendorDetails::class);
    }

    public function section()
    {
        return $this->belongsTo(Sections::class);
    }


   public function getStallsWithRatesAttribute()
    {
        $section = $this->section;
        $stallIds = is_array($this->stall_ids) ? $this->stall_ids : json_decode($this->stall_ids, true);

        if (!$stallIds) return [];

        $stalls = Stalls::whereIn('id', $stallIds)->get();

        return $stalls->map(function ($stall) use ($section) {
            $daily = 0;
            $monthly = 0;

            if ($section->rate_type === 'fixed') {
                // ✅ Take from section’s daily_rate and monthly_rate
                $daily = $section->daily_rate ?? 0;
                $monthly = $section->monthly_rate ?? 0;
            } elseif ($section->rate_type === 'per_sqm') {
                // ✅ Calculate based on size * rate
                $daily = ($section->rate ?? 0) * $stall->size;
                $monthly = $daily * 30;
            }

            return [
                'id' => $stall->id,
                'stall_number' => $stall->stall_number,
                'size' => $stall->size,
                'daily_rent' => $daily,
                'monthly_rent' => $monthly,
            ];
        });
    }

    public function rented()
    {
        return $this->hasOne(Rented::class, 'application_id');
    }

       public function marketRegistration()
    {
        return $this->hasOne(MarketRegistration::class, 'application_id');
    }

    public function stallChangeRequests()
{
    return $this->hasMany(StallChangeRequest::class, 'application_id');
}

}
