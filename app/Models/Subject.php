<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $table = 'subjects';
    protected $fillable = [
        'semester_id',
        'subject_code',
        'des_title',
        'total_units',
        'lec_units',
        'lab_units',
        'total_hrs',
        'total_lec_hrs',
        'total_lab_hrs',
        'pre_requisite',
    ];

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }
}
