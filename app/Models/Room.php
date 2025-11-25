<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $table = 'rooms';
    protected $fillable = [
        'roomNumber',
        'type',
        'capacity',
    ];

    public function availabilities()
    {
        return $this->hasMany(RoomAvailability::class);
    }
}
