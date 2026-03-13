<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class CashTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'quantity',
        'amount',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'amount' => 'decimal:2',
    ];

    // Relationships
    public function payments()
    {
        return $this->hasMany(CashTicketsPayment::class);
    }

  
}
