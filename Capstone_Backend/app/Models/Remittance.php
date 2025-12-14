<?php

namespace App\Models;

use App\Models\MainCollector;
use App\Models\Remittanceable;
use App\Models\InchargeCollector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Remittance extends Model
{
    use HasFactory;
protected $table = "remittance";
 protected $fillable = [
    'remit_date',
    'amount',
    'remitted_by',
    'received_by',
    'status',
];


public function remittanceables()
{
    return $this->hasMany(Remittanceable::class);
}


    public function remittedBy()
    {
        return $this->belongsTo(InchargeCollector::class, 'remitted_by');
    }

    public function receivedBy()
    {
        return $this->belongsTo(MainCollector::class, 'received_by');
    }


}
