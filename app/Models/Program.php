<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    protected $table = 'programs';

    protected $fillable = [
        'program_name',
        'abbreviation',
        'year_from',
        'year_to',
        'status',
    ];

    public function semesters()
    {
        return $this->hasMany(Semester::class);
    }
}
