<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketProductPriceHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'market_product_id',
        'old_price',
        'new_price',
        'change_amount',
        'change_percentage',
        'effective_date',
     
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'change_percentage' => 'decimal:2',
        'effective_date' => 'datetime',
    ];

    /**
     * The product this price history belongs to.
     */
    public function product()
    {
        return $this->belongsTo(MarketProduct::class, 'market_product_id');
    }
}