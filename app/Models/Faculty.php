<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Expertise;
use App\Models\FacultyAvailability;

class Faculty extends Model
{
    protected $table = 'faculties';
    protected $fillable = [
        'user_id',
        'designation',
        'department',
        'profile_picture',
        'deload_units',
        't_load_units',
        'overload_units',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function expertises()
    {
        return $this->hasMany(Expertise::class);
    }

    public function availabilities()    {
        return $this->hasMany(FacultyAvailability::class);
    }

    public function loadings()
    {
        return $this->hasMany(FacultyLoading::class);
    }

    
}
