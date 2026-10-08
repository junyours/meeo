<?php

namespace App\Models;

use App\Models\Collection;
use App\Models\CollectionSession;
use App\Models\VendorDetails;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'username',
        'password',
        'role',
    ];

    public function collectionSessions()
    {
        return $this->hasMany(
            CollectionSession::class,
            'collector_id'
        );
    }

    public function collections()
    {
        return $this->hasMany(
            Collection::class,
            'collector_id'
        );
    }

    public function verifiedCollectionSessions()
    {
        return $this->hasMany(
            CollectionSession::class,
            'verified_by'
        );
    }

    public function vendor()
    {
        return $this->hasOne(
            VendorDetails::class,
            'user_id'
        );
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [];
}