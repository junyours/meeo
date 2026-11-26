<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Animal extends Model
{
    use SoftDeletes;

    protected $table = 'animals';

    protected $fillable = [
        'name',
        'slaughter_fee_per_kilo',
        'ante_mortem_fee_per_head',
        'post_mortem_fee_per_kilo',
        'coral_fee_per_head',
        'permit_to_slh_fee_per_head',
        'minimum_weight_kg',
        'minimum_weight_fee',
        'hides_fee_per_head',
        'is_active',
    ];

    protected $casts = [
        'slaughter_fee_per_kilo' => 'decimal:2',
        'ante_mortem_fee_per_head' => 'decimal:2',
        'post_mortem_fee_per_kilo' => 'decimal:2',
        'coral_fee_per_head' => 'decimal:2',
        'permit_to_slh_fee_per_head' => 'decimal:2',
        'minimum_weight_kg' => 'decimal:3',
        'minimum_weight_fee' => 'decimal:2',
        'hides_fee_per_head' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function collectionItems()
    {
        return $this->hasMany(
            SlaughterhouseCollectionItem::class,
            'animal_id'
        );
    }
}
