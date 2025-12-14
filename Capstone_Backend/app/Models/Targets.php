<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Targets extends Model
{
    use HasFactory;

       protected $fillable = [
        'user_id', 'module', 'annual_target', 'year'
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }
}
