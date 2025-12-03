<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FacultyLoading;
use App\Models\Faculty;
use App\Models\Subject;
use App\Models\CreateSchedule;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Exception;
use Carbon\Carbon;


class FacultyLoadingController extends Controller
{
    public function getFacultyLoading()
    {
        try {
            // Fetch all faculty loading records with related faculty, subject, and room data
            $facultyLoadings = FacultyLoading::with(['faculty.user', 'subject', 'room'])->get();

            return response()->json([
                'success' => true,
                'data'    => $facultyLoadings
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch faculty loading data.'
            ], 500);
        }
    }
    public function getFacultySchedules()
    {
        try {
            // Fetch all schedules with related faculty, subject, and room data
            $schedules = FacultyLoading::with(['faculty.user', 'subject', 'room'])->get();

            // Format the schedules for better readability
            $formattedSchedules = $schedules->map(function ($schedule) {
                return [
                    'id'            => $schedule->id,
                    'faculty_name'  => $schedule->faculty->user->name ?? 'N/A',
                    'subject_code'  => $schedule->subject->subject_code ?? 'N/A',
                    'subject_title' => $schedule->subject->des_title ?? 'N/A',
                    'room_number'   => $schedule->room->roomNumber ?? 'N/A',
                    'section'       => $schedule->section,
                    'type'          => $schedule->type,
                    'day'           => $schedule->day,
                    'start_time'    => $schedule->start_time,
                    'end_time'      => $schedule->end_time,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $formattedSchedules
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch faculty schedules.'
            ], 500);
        }
    }

    public function getCurrentLoad($id)
    {
        // Get ALL FacultyLoading entries for the faculty
        $facultyLoadings = FacultyLoading::where('faculty_id', $id)->get();
        $assignedSubjectIds = $facultyLoadings->pluck('subject_id')->toArray();

        // Sum units from all sections by joining with the subjects table
        $currentLoadUnits = FacultyLoading::where('faculty_id', $id)
            // FIX: Changed 'faculty_loading' to 'faculty_loadings'
            ->join('subjects', 'faculty_loadings.subject_id', '=', 'subjects.id')
            ->sum(DB::raw('COALESCE(subjects.total_hrs, (subjects.total_lec_hrs + subjects.total_lab_hrs), 0)'));


        return response()->json([
            'current_load_units' => (float)$currentLoadUnits,
            'assigned_subject_ids' => array_unique($assignedSubjectIds), 
        ]);
    }
    
    /**
     * Assign a subject and its schedule to a faculty, with load and conflict checks.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignSubject(Request $request)
    {
        $request->validate([
            'facultyId' => 'required|exists:faculties,id',
            'subjectId' => 'required|exists:subjects,id',
            'schedules' => 'required|array|min:1',
            'schedules.*.type' => 'required|in:LEC,LAB',
            'schedules.*.day' => 'required|string',
            'schedules.*.time' => 'required|string', // e.g., "08:00-10:00"
            'schedules.*.roomId' => 'required|exists:rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $facultyId = $request->facultyId;
            $subjectId = $request->subjectId;

            // Fetch faculty load limits
            $faculty = Faculty::findOrFail($facultyId);
            $maxNormalLoad = $faculty->t_load_units ?? 0;
            $maxOverload = $faculty->overload_units ?? 0;
            $totalAllowedLoad = $maxNormalLoad + $maxOverload;

            // Fetch subject
            $subject = Subject::findOrFail($subjectId);
            
            // 1. Calculate New Subject Units (Based on requested hierarchy: total_hrs OR L+L sum)
            $newSubjectUnits = $subject->total_hrs 
                             ?? (($subject->total_lec_hrs ?? 0) + ($subject->total_lab_hrs ?? 0));
            $newSubjectUnits = (float)$newSubjectUnits;

            if ($newSubjectUnits <= 0) {
                throw new Exception("Cannot assign subject '{$subject->subject_code}' because its calculated unit load is 0. Please check the 'total_hrs', 'total_lec_hrs', and 'total_lab_hrs' columns.");
            }

            // 2. Calculate Current Assigned Units (Sum ALL sections)
            $currentLoadUnits = FacultyLoading::where('faculty_id', $facultyId)
                // FIX: Changed 'faculty_loading' to 'faculty_loadings'
                ->join('subjects', 'faculty_loadings.subject_id', '=', 'subjects.id')
                ->sum(DB::raw('COALESCE(subjects.total_hrs, (subjects.total_lec_hrs + subjects.total_lab_hrs), 0)'));

            // 3. Final load check
            $potentialTotalLoad = (float)$currentLoadUnits + $newSubjectUnits;
            if ($potentialTotalLoad > $totalAllowedLoad) {
                throw new Exception(
                    "Load Limit Exceeded: Adding this subject ({$newSubjectUnits} units) " .
                    "will result in a total load of {$potentialTotalLoad} units, " .
                    "which exceeds the maximum allowed load of {$totalAllowedLoad} units " .
                    "({$maxNormalLoad} Normal + {$maxOverload} Overload)."
                );
            }

            // 4. Proceed with Schedule and Conflict Checks
            $createdSchedules = [];
            foreach ($request->schedules as $sched) {
                $times = explode('-', $sched['time']);
                if (count($times) !== 2) {
                    throw new Exception("Invalid time format for " . $sched['day']);
                }
                $startTime = trim($times[0]);
                $endTime = trim($times[1]);
                
                // --- CONFLICT CHECK 1: FACULTY CONFLICT ---
                $facultyConflict = FacultyLoading::where('faculty_id', $facultyId)
                    ->where('day', $sched['day'])
                    ->where(function ($query) use ($startTime, $endTime) {
                        // Conflict condition: new_start < existing_end AND new_end > existing_start
                        $query->where('start_time', '<', $endTime)
                              ->where('end_time', '>', $startTime);
                    })
                    ->exists();

                if ($facultyConflict) {
                    throw new Exception("Conflict: Faculty is already assigned a class on {$sched['day']} from {$startTime} to {$endTime}. Assignment failed.");
                }

                // --- CONFLICT CHECK 2: ROOM CONFLICT ---
                $roomConflict = FacultyLoading::where('room_id', $sched['roomId'])
                    ->where('day', $sched['day'])
                    ->where(function ($query) use ($startTime, $endTime) {
                        // Conflict condition: new_start < existing_end AND new_end > existing_start
                        $query->where('start_time', '<', $endTime)
                              ->where('end_time', '>', $startTime);
                    })
                    ->exists();

                if ($roomConflict) {
                    // Fetch room number for better user feedback
                    $room = Room::find($sched['roomId']); 
                    $roomNumber = $room ? $room->roomNumber : 'Unknown Room';

                    throw new Exception("Conflict: Room {$roomNumber} is already occupied on {$sched['day']} from {$startTime} to {$endTime}. Assignment failed.");
                }
                
                // 5. Create the new schedule entry
                $newSchedule = FacultyLoading::create([
                    'faculty_id' => $facultyId,
                    'subject_id' => $subjectId,
                    'room_id'    => $sched['roomId'],
                    'type'       => $sched['type'],
                    'day'        => $sched['day'],
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
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
            ], 422);
        }
    }

    public function getFacultySchedule($facultyId)
    {
        try {
            // Fetch schedules for the faculty
            // Eager load 'subject' and 'room' relationships to get names/codes
            $schedules = FacultyLoading::with(['subject', 'room'])
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
            $totalScheduledClasses = FacultyLoading::count();

            // Count pila ka unique faculty ang naay schedule (active)
            $totalFacultyActive = FacultyLoading::distinct('faculty_id')->count('faculty_id');

            // Count pila ka unique rooms ang nagamit sa schedule
            $totalRoomsUtilized = FacultyLoading::distinct('room_id')->count('room_id');

            // Count pila ka unique subjects ang gitudlo
            $totalSubjectsTaught = FacultyLoading::distinct('subject_id')->count('subject_id');


            // =========================================================
            // PART B: TODAY'S DETAILS (Listahan para karon ra nga adlaw)
            // =========================================================

            // Fetch Schedules ONLY for TODAY
            $todaySchedules = FacultyLoading::with(['subject', 'room', 'faculty.user'])
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
                    'section'       => $sched->section,
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

    public function getFacultyLoadingReports()
    {
        // Fetch lahat ng FacultyLoading records
        $facultyLoadings = FacultyLoading::with('faculty.user','subject','room')->get(); 
        // Masyadong mahaba ang variable name na $getFacultyLoading, ginawa kong $facultyLoadings para mas malinis.

        return response()->json([
            'success' => true,
            "facultyLoading" => $facultyLoadings,
        ], 200);

    }

    public function getClassScheduleReports()
    {
        $classSchedule = CreateSchedule::with('facultyLoading.faculty.user', 'facultyLoading.subject','facultyLoading.room')->get();

        return response()->json([
            'success' => true,
            "classSchedule" => $classSchedule,
        ], 200);
    }
}
