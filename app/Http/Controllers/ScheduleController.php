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
        // 1. Get filter parameters: checks POST body first, then GET query string
        $filterYear = $request->input('year') ?? $request->query('year');
        $filterSection = $request->input('section') ?? $request->query('section');

        // Start building the query
        // IMPORTANT: Assuming CreateSchedule model has direct columns 'section' and 'year_level'
        $query = CreateSchedule::with('facultyLoading.faculty', 'facultyLoading.subject', 'facultyLoading.room','facultyLoading.faculty.user');

        // 2. Apply filtering conditions
        if ($filterSection) {
            $query->where('section', $filterSection);
        }

        if ($filterYear) {
            $query->where('year_level', $filterYear); // Assuming column is 'year_level'
        }
        
        // 3. Execute the query
        $schedules = $query->get();

        // 4. CHECK FOR EMPTY RESULTS
        if ($schedules->isEmpty()) {
            
            // Construct a descriptive message
            $message = 'No class schedules found for ' . 
                       ($filterYear ? 'Year ' . $filterYear : 'the selected Year') . 
                       ' and Section ' . 
                       ($filterSection ?? 'the selected Section') . 
                       '. Please check your filters or add a new schedule.';

            // Return a 200 OK response with a clear message and an empty data array
            return response()->json([
                'success' => true, 
                'message' => $message,
                'data' => [] // Ensure 'data' is still present, but empty
            ], 200);
        }

        // 5. If data is found, return the results
        return response()->json(['success' => true, 'data' => $schedules], 200);
    }

    public function store(Request $request)
    {
        // 1. Validate Input
        $request->validate([
            'subject_id' => 'required|integer',
            'room_id'    => 'required|integer',
            'day'        => 'required|string',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i|after:start_time',
            'year_level' => 'required|integer|min:1|max:5', 
            'section'    => 'required|string',
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

        // 3. Check if Slot is taken
        if (CreateSchedule::where('faculty_loading_id', $facultyLoading->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'This slot is already assigned.'], 409);
        }

        // 4. Check Section Conflict (Same Year & Section cannot have 2 classes at same time)
        // We filter by year_level AND section now
        $sectionConflict = CreateSchedule::where('year_level', $request->year_level) // <--- Check Year
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
            return response()->json([
                'success' => false,
                'message' => "Conflict: Year {$request->year_level} - Section {$request->section} already has a class at this time."
            ], 409);
        }

        // 5. Save
        $schedule = CreateSchedule::create([
            'faculty_loading_id' => $facultyLoading->id,
            'year_level'         => $request->year_level, // <--- Save Year Level
            'section'            => $request->section
        ]);

        return response()->json([
            'success' => true, 
            'message' => 'Class schedule created successfully!', 
            'data' => $schedule
        ], 201);
    }
}
