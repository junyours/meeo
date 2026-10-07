<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CollectionSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'collector_id',
        'collection_date',
        'expected_total',
        'collected_total',
        'cash_turned_over',
        'difference',
        'status',
        'verified_by',
        'verified_at',
        'remarks',
    ];

    protected $casts = [
        'collection_date' => 'date',
        'expected_total' => 'decimal:2',
        'collected_total' => 'decimal:2',
        'cash_turned_over' => 'decimal:2',
        'difference' => 'decimal:2',
        'verified_at' => 'datetime',
    ];

    public function collector()
    {
        return $this->belongsTo(
            User::class,
            'collector_id'
        );
    }

    public function verifier()
    {
        return $this->belongsTo(
            User::class,
            'verified_by'
        );
    }

    public function collections()
    {
        return $this->hasMany(
            Collection::class,
            'collection_session_id'
        );
    }
}