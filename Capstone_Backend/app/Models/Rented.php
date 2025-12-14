<?php

namespace App\Models;

use App\Models\Stalls;
use App\Models\Payments;
use App\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Rented extends Model
{
    use HasFactory;

    protected $table = 'rented';
      protected $fillable = ['application_id', 'stall_id','monthly_rent',
      'daily_rent','last_payment_date','missed_days','next_due_date','status'];


 

public function application()
{
    return $this->belongsTo(Application::class, 'application_id');
}

public function stall()
{
    return $this->belongsTo(Stalls::class, 'stall_id');
}
public function payments()
{
    return $this->hasMany(Payments::class);
}


protected $casts = [
        'last_payment_date' => 'datetime',
    ];


    
}
