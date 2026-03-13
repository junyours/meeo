<?php

namespace App\Models;

use App\Models\Sections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Area extends Model
{
    use HasFactory, SoftDeletes;


     protected $fillable = [
        'name',
        'column_count',  
        'position_x',    
        'position_y',
        'rows_per_column',
        'sort_order',
    ];


     protected $casts = [
        'rows_per_column' => 'array', 
    ];
    public function sections()
    {
        return $this->hasMany(Sections::class, 'area_id');
    }
}
