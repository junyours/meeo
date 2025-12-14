<?php

namespace App\Models;

use App\Models\Sections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Area extends Model
{
    use HasFactory;


     protected $fillable = [
        'name',
            
        'column_count',  
        'position_x',    
        'position_y'  ,
         'rows_per_column', 
    ];


     protected $casts = [
        'rows_per_column' => 'array', 
    ];
    public function sections()
    {
        return $this->hasMany(Sections::class, 'area_id');
    }
}
