<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacultyLoading extends Model
{
     use HasFactory;

     protected $fillable = [
        'faculty_id',
        'subject_id',
        'room_id',
        'section',
        'type',
        'day',
        'start_time',
        'end_time',
    ];

    public function faculty() 
    { 
        return $this->belongsTo(Faculty::class); 
    }

    public function subject() 
    { 
        return $this->belongsTo(Subject::class); 
    }
    public function room()    
    { 
        return $this->belongsTo(Room::class); 
    }
    public function program()    
    { 
        return $this->belongsTo(Program::class); 
    }
   
}
