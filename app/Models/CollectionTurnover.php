<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CollectionTurnover extends Model
{
    use HasFactory;

    protected $fillable = [
        'collection_session_id',
        'turnover_client_id',
        'expected_total',
        'collected_total',
        'cash_turned_over',
        'difference',
        'remarks',
        'submitted_at',
    ];

    protected $casts = [
        'expected_total' => 'decimal:2',
        'collected_total' => 'decimal:2',
        'cash_turned_over' => 'decimal:2',
        'difference' => 'decimal:2',
        'submitted_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CollectionSession::class, 'collection_session_id');
    }

    public function collections()
    {
        return $this->hasMany(Collection::class, 'collection_turnover_id');
    }
}
