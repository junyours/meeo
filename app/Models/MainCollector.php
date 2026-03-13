<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MainCollector extends Model
{
    use HasFactory;

          protected $table = "main_collector_details";
    protected $fillable = ['fullname','age','gender','address','contact_number','emergency_contact','user_id','area','Status'];

public function user()
{
    return $this->belongsTo(User::class);
}
}
