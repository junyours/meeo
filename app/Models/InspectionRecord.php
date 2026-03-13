<?php

namespace App\Models;

use App\Models\Animals;
use App\Models\Customer;
use App\Models\MeatInspector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InspectionRecord extends Model
{
    use HasFactory;
protected $table ="inspection_record";
        protected $fillable = [
        'animal_id',          // foreign key to Animals table
        'customer_id',
        'inspection_type',    // ante-mortem / post-mortem
        'health_status',
        'defects',
        'remarks',
        'notified',
        'inspector_id',       // foreign key to MeatInspector
    ];

    public function animal()
    {
        return $this->belongsTo(Animals::class, 'animal_id');
    }

       public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
    public function inspector()
    {
        return $this->belongsTo(MeatInspector::class, 'inspector_id');
    }
}
