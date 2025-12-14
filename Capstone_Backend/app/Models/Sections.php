<?php

namespace App\Models;

use App\Models\Area;
use App\Models\Stalls;
use App\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sections extends Model
{
    use HasFactory;
protected $table='section';
     protected $fillable = [
    'name', 
    'area_id', 
    'rate_type',
    'rate',
    'monthly_rate',
    'daily_rate',
 'column_index',
    'row_index',
];

public function stalls() {
    return $this->hasMany(Stalls::class, 'section_id'); // singular
}

public function applications()
{
    return $this->hasMany(Application::class, 'section_id');
}

    public function area()
{
    return $this->belongsTo(Area::class, 'area_id');
}
}
