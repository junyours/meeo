<?php

namespace App\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Permit extends Model
{
    use HasFactory;

    protected $table = "permits";
 protected $fillable = ['tenant_id','business_permit','sanitary_permit','market_registration','dti_registration','remarks'];
public function tenant()
{
    return $this->belongsTo(Tenant::class);
}
}
