<?php

namespace App\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sale extends Model
{
    use HasFactory;

      protected $table = "sales";
    protected $fillable = ['tenant_id','week1','week2','week3','week4','total_sales'];


    public function tenant()
{
    return $this->belongsTo(Tenant::class);
}
}
