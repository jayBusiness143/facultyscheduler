<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Faculty;

class Expertise extends Model
{
    protected $table = 'expertises';
    protected $fillable = [
        'faculty_id',
        'list_of_expertise',
    ];

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }
}
