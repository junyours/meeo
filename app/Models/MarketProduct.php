<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketProduct extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'price', 'unit', 'image', 'available', 'category_id'];

    protected $casts = [
        'available' => 'boolean',
        'price' => 'decimal:2'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
