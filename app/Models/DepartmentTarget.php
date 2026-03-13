<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class DepartmentTarget extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'department_id',
        'annual_target',
        'year',
        'monthly_targets',
        'january_collection',
        'february_collection',
        'march_collection',
        'april_collection',
        'may_collection',
        'june_collection',
        'july_collection',
        'august_collection',
        'september_collection',
        'october_collection',
        'november_collection',
        'december_collection',
    ];

    protected $casts = [
        'annual_target' => 'decimal:2',
        'monthly_targets' => 'array',
        'january_collection' => 'decimal:2',
        'february_collection' => 'decimal:2',
        'march_collection' => 'decimal:2',
        'april_collection' => 'decimal:2',
        'may_collection' => 'decimal:2',
        'june_collection' => 'decimal:2',
        'july_collection' => 'decimal:2',
        'august_collection' => 'decimal:2',
        'september_collection' => 'decimal:2',
        'october_collection' => 'decimal:2',
        'november_collection' => 'decimal:2',
        'december_collection' => 'decimal:2',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function getTotalCollectionAttribute()
    {
        return $this->january_collection + $this->february_collection + 
               $this->march_collection + $this->april_collection + 
               $this->may_collection + $this->june_collection + 
               $this->july_collection + $this->august_collection + 
               $this->september_collection + $this->october_collection + 
               $this->november_collection + $this->december_collection;
    }

    public function getProgressPercentageAttribute()
    {
        if ($this->annual_target == 0) return 0;
        return ($this->total_collection / $this->annual_target) * 100;
    }

    public function getMonthlyCollection($month)
    {
        $monthColumns = [
            'january' => 'january_collection',
            'february' => 'february_collection',
            'march' => 'march_collection',
            'april' => 'april_collection',
            'may' => 'may_collection',
            'june' => 'june_collection',
            'july' => 'july_collection',
            'august' => 'august_collection',
            'september' => 'september_collection',
            'october' => 'october_collection',
            'november' => 'november_collection',
            'december' => 'december_collection',
        ];

        return $this->{$monthColumns[strtolower($month)]} ?? 0;
    }

    public function setMonthlyCollection($month, $amount)
    {
        $monthColumns = [
            'january' => 'january_collection',
            'february' => 'february_collection',
            'march' => 'march_collection',
            'april' => 'april_collection',
            'may' => 'may_collection',
            'june' => 'june_collection',
            'july' => 'july_collection',
            'august' => 'august_collection',
            'september' => 'september_collection',
            'october' => 'october_collection',
            'november' => 'november_collection',
            'december' => 'december_collection',
        ];

        if (isset($monthColumns[strtolower($month)])) {
            $this->{$monthColumns[strtolower($month)]} = $amount;
        }
    }
}
