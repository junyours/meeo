<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SlaughterhouseCollectionItem extends Model
{
    use SoftDeletes;

    protected $table = 'slaughterhouse_collection_items';

    protected $fillable = [
        'slaughterhouse_collection_id',
        'quantity',
        'animal_type',
        'total_kilos',
        'price_kilos',
        'ante_mortem',
        'post_mortem',
        'hides',
        'slaughter_fee',
        'coral_fee',
        'total_amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'total_kilos' => 'decimal:3',
        'price_kilos' => 'decimal:2',
        'ante_mortem' => 'decimal:2',
        'post_mortem' => 'decimal:2',
        'hides' => 'decimal:2',
        'slaughter_fee' => 'decimal:2',
        'coral_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function collection()
    {
        return $this->belongsTo(SlaughterhouseCollection::class, 'slaughterhouse_collection_id');
    }
}
