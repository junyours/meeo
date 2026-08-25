<?php

namespace App\Models;

use App\Models\OfficeActivities;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficeActivitiesImages extends Model
{
    use HasFactory;

    protected $table = 'office_activities_images';

    protected $fillable = [
        'office_activity_id',
        'image',
    ];

    /**
     * An image belongs to one office activity.
     */
    public function activity()
    {
        return $this->belongsTo(OfficeActivities::class, 'office_activity_id');
    }
}