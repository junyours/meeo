<?php

namespace App\Models;

use App\Models\SlaughterPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MeatInspector extends Model
{
    use HasFactory;

      protected $table = "meat_inspector_details";
    protected $fillable = ['fullname','age','gender','address','contact_number','emergency_contact','user_id','Status','profile_picture'];



  public function payments()
    {
        return $this->hasMany(SlaughterPayment::class, 'inspector_id');
    }
}
