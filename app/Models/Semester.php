<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Semester extends Model
{
    protected $table = 'semesters';
    protected $fillable = [
        'program_id',
        'year_level',
        'semester_level',
        'status',
        'start_date',
        'end_date',   
    ];

    protected $casts = [
        'status' => 'integer',
        'start_date' => 'date:Y-m-d', 
        'end_date' => 'date:Y-m-d',   
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }
}
