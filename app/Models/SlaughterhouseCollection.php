<?php

namespace App\Models;

use App\Models\SlaughterhouseCollectionItem;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SlaughterhouseCollection extends Model
{
     use SoftDeletes;

    protected $table = 'slaughterhouse_collections';

    protected $fillable = [
        'collection_date',
        'client_transaction_id',
        'or_number',
        'customer_name',
        'grand_total',
        'user_id',
    ];

    protected $casts = [
        'collection_date' => 'date',
        'grand_total' => 'decimal:2',
    ];

    // User who recorded the collection
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Animal collection items
    public function items()
    {
        return $this->hasMany(
            SlaughterhouseCollectionItem::class,
            'slaughterhouse_collection_id'
        );
    }
}
