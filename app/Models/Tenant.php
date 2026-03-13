<?php

namespace App\Models;

use App\Models\Sale;
use App\Models\Stalls;
use App\Models\Permit;
use App\Models\Source;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory;
    protected $table = "tenants";
    protected $fillable = ['stall_id','fullname','age','gender','address','contact_number','emergency_contact',
    'business_name','years_in_operation'];
    public function stall() {
    return $this->belongsTo(Stalls::class);
}
public function product() {
    return $this->hasOne(Product::class);
}

public function sale() {
    return $this->hasOne(Sale::class);
}

public function source() {
    return $this->hasOne(Source::class);
}

public function permit() {
    return $this->hasOne(Permit::class);
}


}
