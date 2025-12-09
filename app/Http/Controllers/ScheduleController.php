<?php

namespace App\Http\Controllers;

use App\Models\CreateSchedule;
use App\Models\FacultyLoading;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function getScheduleByRoomId($roomId)
    {
        $schedules = CreateSchedule::whereHas('facultyLoading.room', function ($query) use ($roomId) {
            $query->where('room_id', $roomId);
        })
        ->with([
            'facultyLoading.room',
            'facultyLoading.subject',
            'facultyLoading.faculty.user'
        ])
        ->get();
        $roomDetails = null;
        if ($schedules->isNotEmpty()) {
            $room = $schedules->first()->facultyLoading->room;
            $roomDetails = [
                'room_id' => $room->id,
                'room_number' => $room->roomNumber,
                'room_type' => $room->type,
                'capacity' => $room->capacity,
            ];
        } else {
            return response()->json([
                'success' => true,
                'room_details' => null,
                'schedules' => [],
                'message' => 'No schedules found for Room ID ' . $roomId
            ]);
        }
        $scheduleList = $schedules->map(function ($schedule) {
            return [
                'schedule_id' => $schedule->id,
                'year_level' => $schedule->year_level,
                'section' => $schedule->section,
                'subject_code' => $schedule->facultyLoading->subject->subject_code,
                'des_title' => $schedule->facultyLoading->subject->des_title,
                'type' => $schedule->facultyLoading->type,
                'day' => $schedule->facultyLoading->day,
                'start_time' => $schedule->facultyLoading->start_time,
                'end_time' => $schedule->facultyLoading->end_time,
                'faculty_name' => $schedule->facultyLoading->faculty->user->name ?? 'N/A' 
            ];
        })->values();

        return response()->json([
            'success' => true,
            'room_details' => $roomDetails,
            'schedules' => $scheduleList
        ]);
    }

    public function getSchedules(Request $request)
    {
        // This method can be implemented to retrieve all schedules without filters
        $schedules = CreateSchedule::with('facultyLoading.faculty', 'facultyLoading.subject', 'facultyLoading.room')->get();

        return response()->json(['success' => true, 'data' => $schedules], 200);
    }


public function index(Request $request)
{
    // Accept both 'year_level' (preferred) and legacy 'year' param, and 'program_id'
    $filterYear = $request->input('year_level') ?? $request->query('year_level') 
               ?? $request->input('year') ?? $request->query('year');

    $filterSection = $request->input('section') ?? $request->query('section');
    $filterProgram = $request->input('program_id') ?? $request->query('program_id');

    // Start building the query
    $query = CreateSchedule::with(
        'facultyLoading.program',
        'facultyLoading.faculty',
        'facultyLoading.subject',
        'facultyLoading.room',
        'facultyLoading.faculty.user'
    );

    // Apply filtering conditions
    if ($filterSection) {
        $query->where('section', $filterSection);
    }

    if ($filterYear) {
        $query->where('year_level', $filterYear);
    }

    if ($filterProgram) {
        $query->where('program_id', $filterProgram);
    }

    // Execute the query
    $schedules = $query->get();

    // If no results, return a helpful message but success=true with empty data
    if ($schedules->isEmpty()) {
        $messageParts = [];
        if ($filterProgram) $messageParts[] = 'Program ' . $filterProgram;
        if ($filterYear) $messageParts[] = 'Year ' . $filterYear;
        if ($filterSection) $messageParts[] = 'Section ' . $filterSection;

        $message = $messageParts ? 'No class schedules found for ' . implode(', ', $messageParts) . '.' :
                   'No class schedules found. Please check your filters or add a new schedule.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => []
        ], 200);
    }

    // Return results
    return response()->json(['success' => true, 'data' => $schedules], 200);
}

    public function store(Request $request)
    {
        // 1. Validate Input (ADDED 'program_id')
        $request->validate([
            'subject_id' => 'required|integer',
            'room_id'    => 'required|integer',
            'day'        => 'required|string',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i|after:start_time',
            'year_level' => 'required|integer|min:1|max:5', 
            'section'    => 'required|string',
            'program_id' => 'required|integer|exists:programs,id', // <--- NEW VALIDATION
        ]);

        // 2. Find Faculty Loading ID
        $startTime = $request->start_time . ':00';
        $endTime = $request->end_time . ':00';

        $facultyLoading = FacultyLoading::where('subject_id', $request->subject_id)
            ->where('room_id', $request->room_id)
            ->where('day', $request->day)
            ->where('start_time', $startTime)
            ->where('end_time', $endTime)
            ->first();

        if (!$facultyLoading) {
            return response()->json(['success' => false, 'message' => 'Invalid Schedule: Slot not found in Faculty Loading.'], 404);
        }

        // 3. Check if Slot is taken — return detailed info about the existing assignment
        $existingSchedule = CreateSchedule::where('faculty_loading_id', $facultyLoading->id)
            ->with(['facultyLoading.subject', 'facultyLoading.faculty.user', 'facultyLoading.room','facultyLoading.program'])
            ->first();

        if ($existingSchedule) {
            $fl = $existingSchedule->facultyLoading;
            $subjectCode = $fl->subject->subject_code ?? 'N/A';
            $facultyName = $fl->faculty->user->name ?? 'Unassigned';
            $roomNumber = $fl->room->roomNumber ?? 'TBA';
            $day = $fl->day ?? 'N/A';
            $startTime = $fl->start_time ? date('H:i', strtotime($fl->start_time)) : 'N/A';
            $endTime = $fl->end_time ? date('H:i', strtotime($fl->end_time)) : 'N/A';

            $message = "This slot is already assigned to Year {$existingSchedule->year_level} Section {$existingSchedule->section} for subject {$subjectCode}, taught by {$facultyName} in room {$roomNumber} on {$day} from {$startTime} to {$endTime}.";

            return response()->json([
                'success' => false,
                'message' => $message,
                'existing_assignment' => [
                    'schedule_id' => $existingSchedule->id,
                    'program_id' => $existingSchedule->program_id,
                    'year_level' => $existingSchedule->year_level,
                    'section' => $existingSchedule->section,
                    'subject_code' => $subjectCode,
                    'faculty_name' => $facultyName,
                    'room_number' => $roomNumber,
                    'day' => $day,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]
            ], 409);
        }

        // 4. Check Section Conflict (Same Year, Section, AND Program cannot have 2 classes at same time)
        $sectionConflict = CreateSchedule::where('program_id', $request->program_id) // <--- Check Program
            ->where('year_level', $request->year_level) 
            ->where('section', $request->section)
            ->whereHas('facultyLoading', function ($query) use ($facultyLoading) {
                $query->where('day', $facultyLoading->day)
                    ->where(function ($q) use ($facultyLoading) {
                        $q->where('start_time', '<', $facultyLoading->end_time)
                            ->where('end_time', '>', $facultyLoading->start_time);
                    });
            })
            ->first();

        if ($sectionConflict) {
            // You might want to fetch the program name/code here for a better error message
            return response()->json([
                'success' => false,
                'message' => "Conflict: Section {$request->section} (Year {$request->year_level}, Program ID {$request->program_id}) already has a class at this time."
            ], 409);
        }

        // 5. Save (ADDED 'program_id')
        $schedule = CreateSchedule::create([
            'faculty_loading_id' => $facultyLoading->id,
            'year_level'         => $request->year_level, 
            'section'            => $request->section,
            'program_id'         => $request->program_id, // <--- NEW SAVE FIELD
        ]);

        return response()->json([
            'success' => true, 
            'message' => 'Class schedule created successfully!', 
            'data' => $schedule
        ], 201);
    }
}
