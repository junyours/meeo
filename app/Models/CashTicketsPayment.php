<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class CashTicketsPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_ticket_id',
        'amount_paid',
        'payment_date',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
    ];

    // Relationships
    public function cashTicket()
    {
        return $this->belongsTo(CashTicket::class);
    }


}
