<?php

namespace App\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

      protected $table = "products";
    protected $fillable = ['tenant_id','type','estimated_sales','peak_time'];


    public function tenant()
{
    return $this->belongsTo(Tenant::class);
}
}
