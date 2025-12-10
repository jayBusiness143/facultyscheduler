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

     // Para isama ang 'section' sa JSON output kahit wala sa database column
    protected $appends = ['section']; 

    // Relationship: One FacultyLoading can have many CreateSchedules
    public function schedules()    
    { 
        return $this->hasMany(CreateSchedule::class, 'faculty_loading_id'); 
    }

    // ACCESSOR: Ito ang kukuha ng 'section' mula sa kaugnay na schedules
    public function getSectionAttribute()
    {
        // Kukunin ang lahat ng section mula sa CreateSchedule records
        $sections = $this->schedules->pluck('section')->toArray();
        
        // Kung may maraming section (e.g., Section A, Section B, Section C), pagsasamahin ito.
        // Kung gusto mo lang ang una: $this->schedules->first()->section
        return count($sections) > 0 ? implode(', ', $sections) : null;
    }
   
}
