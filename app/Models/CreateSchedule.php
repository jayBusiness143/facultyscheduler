<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreateSchedule extends Model
{

    protected $fillable = [
        'faculty_loading_id', 
        'year_level', 
        'section'
    ];

    public function facultyLoading()
    {
        return $this->belongsTo(FacultyLoading::class, 'faculty_loading_id');
    }
}
