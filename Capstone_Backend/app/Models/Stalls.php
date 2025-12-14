<?php

namespace App\Models;

use App\Models\Rented;
use App\Models\Sections;
use App\Models\Application;
use App\Models\StallStatusLogs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Stalls extends Model
{
    use HasFactory;

    protected $table = 'stall';
     protected $fillable = [
        'section_id', 'stall_number', 'row_position', 'column_position', 
        'size', 'status','is_active','message','pending_removal'
    ];

        protected $casts = [
        'is_active' => 'boolean',
        'pending_removal' => 'boolean',

    ];
    public function section()
    {
        return $this->belongsTo(Sections::class,'section_id');
    }
    
      public function rented()
    {
        return $this->hasOne(Rented::class, 'stall_id');  
        // or ->hasMany() if a stall can be rented multiple times
    }

    public function rentals()
{
    return $this->hasMany(Rented::class, 'stall_id');
}

 public function applications()
{
    return $this->belongsToMany(Application::class, 'rented', 'stall_id', 'application_id');
}

  public function statusLogs()
    {
        return $this->hasMany(StallStatusLogs::class, 'stall_id')
                    ->orderBy('created_at', 'desc');
    }

}
