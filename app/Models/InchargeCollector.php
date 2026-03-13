<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InchargeCollector extends Model
{
    use HasFactory;

      protected $table = "incharge_collector_details";
    protected $fillable = ['fullname','age','gender','address','contact_number','emergency_contact','user_id','area','Status','profile_picture',];

public function user()
{
    return $this->belongsTo(User::class);
}

 public function payments()
    {
        return $this->hasMany(Payments::class, 'collector_id');
    }
}
