<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Schedule;
use App\Models\Faculty;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Exception;
use Carbon\Carbon;


class FacultyLoadingController extends Controller
{
    public function assignSubject(Request $request)
    {
        // 1. Validate incoming data
        $request->validate([
            'facultyId' => 'required|exists:faculties,id',
            'subjectId' => 'required|exists:subjects,id',
            'schedules' => 'required|array|min:1',
            'schedules.*.type' => 'required|in:LEC,LAB',
            'schedules.*.day' => 'required|string',
            'schedules.*.time' => 'required|string', // Format: "HH:mm-HH:mm"
            'schedules.*.roomId' => 'required|exists:rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $createdSchedules = [];

            foreach ($request->schedules as $sched) {
                // 2. Parse Time String (e.g., "08:00-09:30")
                $times = explode('-', $sched['time']);
                if (count($times) !== 2) {
                    throw new Exception("Invalid time format for " . $sched['day']);
                }
                $startTime = trim($times[0]); // "08:00"
                $endTime = trim($times[1]);   // "09:30"

                // 3. Check for ROOM Conflicts
                // Logic: (StartA < EndB) AND (EndA > StartB)
                $roomConflict = Schedule::where('room_id', $sched['roomId'])
                    ->where('day', $sched['day'])
                    ->where(function ($query) use ($startTime, $endTime) {
                        $query->where('start_time', '<', $endTime)
                              ->where('end_time', '>', $startTime);
                    })
                    ->with('subject')
                    ->first();

                if ($roomConflict) {
                    $roomName = Room::find($sched['roomId'])->roomNumber ?? 'Selected Room';
                    throw new Exception("Conflict: $roomName is occupied on {$sched['day']} ({$roomConflict->start_time} - {$roomConflict->end_time}) by {$roomConflict->subject->subject_code}.");
                }

                // 4. Check for FACULTY Conflicts
                $facultyConflict = Schedule::where('faculty_id', $request->facultyId)
                    ->where('day', $sched['day'])
                    ->where(function ($query) use ($startTime, $endTime) {
                        $query->where('start_time', '<', $endTime)
                              ->where('end_time', '>', $startTime);
                    })
                    ->first();

                if ($facultyConflict) {
                    $facultyName = Faculty::find($request->facultyId)->name;
                    throw new Exception("Conflict: $facultyName already has a class on {$sched['day']} between $startTime and $endTime.");
                }

                // 5. Save Schedule
                $newSchedule = Schedule::create([
                    'faculty_id' => $request->facultyId,
                    'subject_id' => $request->subjectId,
                    'room_id' => $sched['roomId'],
                    'type' => $sched['type'],
                    'day' => $sched['day'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);

                $createdSchedules[] = $newSchedule;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Subject assigned successfully.',
                'data' => $createdSchedules
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422); // 422 Unprocessable Entity
        }
    }

    public function getFacultySchedule($facultyId)
    {
        try {
            // Fetch schedules for the faculty
            // Eager load 'subject' and 'room' relationships to get names/codes
            $schedules = Schedule::with(['subject', 'room'])
                ->where('faculty_id', $facultyId)
                ->get();

            // Optional: Sort by Day and Time manually if not handled by DB
            // This ensures Monday comes before Tuesday, etc.
            $dayOrder = [
                'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 
                'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7
            ];

            $sortedSchedules = $schedules->sortBy(function ($schedule) use ($dayOrder) {
                return $dayOrder[$schedule->day] * 10000 + (int)str_replace(':', '', $schedule->start_time);
            })->values();

            return response()->json([
                'success' => true,
                'data' => $sortedSchedules
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch schedules.'
            ], 500);
        }
    }

    public function getTodayScheduleStatistics()
{
    try {
        // 1. Get Today's Day Name
        $currentDay = Carbon::now('Asia/Manila')->format('l');

        // =========================================================
        // PART A: GLOBAL COUNTS (Total sa tibuok semester/database)
        // =========================================================
        
        // Count tanan schedule entries sa database
        $totalScheduledClasses = Schedule::count();

        // Count pila ka unique faculty ang naay schedule (active)
        $totalFacultyActive = Schedule::distinct('faculty_id')->count('faculty_id');

        // Count pila ka unique rooms ang nagamit sa schedule
        $totalRoomsUtilized = Schedule::distinct('room_id')->count('room_id');

        // Count pila ka unique subjects ang gitudlo
        $totalSubjectsTaught = Schedule::distinct('subject_id')->count('subject_id');


        // =========================================================
        // PART B: TODAY'S DETAILS (Listahan para karon ra nga adlaw)
        // =========================================================

        // Fetch Schedules ONLY for TODAY
        $todaySchedules = Schedule::with(['subject', 'room', 'faculty.user'])
            ->where('day', $currentDay)
            ->get();

        // Format the details list
        $detailedList = $todaySchedules->map(function ($sched) {
            return [
                'id'            => $sched->id,
                'subject_code'  => $sched->subject->subject_code ?? 'N/A',
                'description'   => $sched->subject->des_title ?? 'N/A',
                'units'         => $sched->subject->total_units ?? 0,
                'type'          => $sched->type,
                'day'           => $sched->day,
                'start_time'    => $sched->start_time,
                'end_time'      => $sched->end_time,
                'room_number'   => $sched->room->roomNumber ?? 'TBA',
                'room_id'       => $sched->room_id,
                'faculty_name'  => $sched->faculty->user->name ?? 'Unassigned',
                'faculty_id'    => $sched->faculty_id,
                'faculty_img'   => $sched->faculty->profile_picture ?? null,
            ];
        })->sortBy(function ($item) {
            return strtotime($item['start_time']);
        })->values();

        // 5. Return JSON Response
        return response()->json([
            'success' => true,
            'day'     => $currentDay,
            'date'    => Carbon::now('Asia/Manila')->toDateString(),
            
            // Kani nga counts kay GLOBAL (Overall totals)
            'counts'  => [
                'total_scheduled_classes' => $totalScheduledClasses,
                'total_faculty_active'    => $totalFacultyActive,
                'total_rooms_utilized'    => $totalRoomsUtilized,
                'total_subjects_taught'   => $totalSubjectsTaught,
            ],
            
            // Kani nga details kay TODAY ONLY
            'details' => $detailedList 
        ], 200);

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch statistics.',
            'error'   => $e->getMessage()
        ], 500);
    }
}
}
