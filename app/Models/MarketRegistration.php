<?php

namespace App\Models;

use App\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MarketRegistration extends Model
{
    use HasFactory;

  
    protected $table = 'market_registration';
    protected $fillable = [
        'application_id',
        'date_issued',
        'pdf_generated_count',
        'expiry_date',
        'signature',
        'renewal_requested','status',
    ];  
  
    protected $casts = [
        'expiry_date'  => 'datetime',
        'date_issued'  => 'datetime', // optional but nice to have
    ];


    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
