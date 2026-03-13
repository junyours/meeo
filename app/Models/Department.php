<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function targets()
    {
        return $this->hasMany(DepartmentTarget::class);
    }

    public function currentYearTarget()
    {
        return $this->hasOne(DepartmentTarget::class)
                    ->where('year', date('Y'));
    }
}
