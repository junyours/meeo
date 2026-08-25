<?php

namespace App\Models;


use App\Models\OfficeActivitiesImages;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficeActivities extends Model
{
    use HasFactory;

    protected $table = 'office_activities';

    protected $fillable = [
        'title',
        'description',
        'activity_type',
        'activity_date',
        'image',
 
    ];

    protected $casts = [
        'activity_date' => 'date',
    ];

    /**
     * An office activity can have many images.
     */
    public function images()
    {
        return $this->hasMany(OfficeActivitiesImages::class, 'office_activity_id');
    }
}