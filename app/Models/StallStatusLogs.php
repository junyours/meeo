<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StallStatusLogs extends Model
{
    use HasFactory;

     protected $table = 'stall_status_logs';

    protected $fillable = [
        'stall_id',
        'is_active',
        'message',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function stall()
    {
        return $this->belongsTo(Stalls::class, 'stall_id');
    }
    
}
