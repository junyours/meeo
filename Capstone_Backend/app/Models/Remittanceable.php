<?php

namespace App\Models;

use App\Models\Remittance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Remittanceable extends Model
{
    use HasFactory;

    protected $fillable = [
        'remittance_id',
        'remittable_id',
        'remittable_type',
    ];

    public function remittance()
    {
        return $this->belongsTo(Remittance::class);
    }

    public function remittable()
    {
        return $this->morphTo();
    }
}
