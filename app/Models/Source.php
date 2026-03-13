<?php

namespace App\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Source extends Model
{
    use HasFactory;

      protected $table = "sources";
    protected $fillable = ['tenant_id','main_source','purchase_location','purchase_frequency','transport_mode'];
public function tenant()
{
    return $this->belongsTo(Tenant::class);
}
}
