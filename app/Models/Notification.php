<?php

namespace App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = ['collector_id', 'message','vendor_id','title','is_read','customer_id'];

       public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
