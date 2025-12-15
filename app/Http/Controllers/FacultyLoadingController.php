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

    // Fix the Current Load Calculation:
    // The previous complex hour calculation resulted in 22.
    // The actual load is almost always calculated by summing the 'total_units' 
    // from the subjects table. This is the MOST LIKELY fix for the 17 vs 22 mismatch.

    $currentLoadUnits = FacultyLoading::where('faculty_id', $id)
        ->join('subjects', 'faculty_loadings.subject_id', '=', 'subjects.id')
        // FIX: Summing the 'total_units' column instead of the complex hours formula
        ->sum('subjects.total_units');


    // **Alternative (if subjects are double-counted in faculty_loadings):**
    // If the faculty_loadings table has MULTIPLE entries for the SAME subject_id 
    // (e.g., one for lecture, one for lab, or one per schedule slot)
    // but the faculty should only get credit ONCE for the subject's units, 
    // you must prevent the double-counting.
    /*
    $currentLoadUnits = FacultyLoading::where('faculty_id', $id)
        ->distinct('subject_id') // Get distinct subject assignments
        ->join('subjects', 'faculty_loadings.subject_id', '=', 'subjects.id')
        ->sum('subjects.total_units');
    */
    // Note: Eloquent/DB query builders can sometimes struggle with SUM and DISTINCT 
    // simultaneously. If the first fix doesn't work, the data structure is likely 
    // the issue and a manual query might be needed, but try the simplest fix first.


    return response()->json([
        'current_load_units' => (float)$currentLoadUnits,
        'assigned_subject_ids' => array_unique($assignedSubjectIds), 
    ]);
}
    
    public function assignSubject(Request $request)
    {
        // 1. Validation (Added pairedDays checks)
        $request->validate([
            'facultyId' => 'required|exists:faculties,id',
            'subjectId' => 'required|exists:subjects,id',
            'schedules' => 'required|array|min:1',
            'schedules.*.type' => 'required|in:LEC,LAB',
            'schedules.*.day' => 'required|string',
            'schedules.*.time' => 'required|string', // e.g., "08:00-10:00"
            'schedules.*.roomId' => 'required|exists:rooms,id',
            'schedules.*.pairedDays' => 'nullable|array', // NEW: Paired days is optional array
            'schedules.*.pairedDays.*' => 'string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday', // NEW: Check elements
        ]);

        DB::beginTransaction();

        try {
            $facultyId = $request->facultyId;
            $subjectId = $request->subjectId;

            // Load Checks (No change)
            $faculty = Faculty::select('id', 't_load_units', 'overload_units')
                ->with(['loadings.subject' => function ($q) {
                    $q->select('id', 'total_hrs', 'total_lec_hrs', 'total_lab_hrs');
                }])
                ->findOrFail($facultyId);

            $maxNormalLoad = $faculty->t_load_units ?? 0;
            $maxOverload = $faculty->overload_units ?? 0;
            $totalAllowedLoad = (float)($maxNormalLoad + $maxOverload);

            $subject = Subject::findOrFail($subjectId);

            $newSubjectUnits = $subject->total_hrs 
                ?? (($subject->total_lec_hrs ?? 0) + ($subject->total_lab_hrs ?? 0));
            $newSubjectUnits = (float)$newSubjectUnits;

            if ($newSubjectUnits <= 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Cannot assign subject '{$subject->subject_code}' because its calculated unit load is 0."
                ], 422);
            }

            $currentLoadUnits = $faculty->loadings->sum(function ($loading) {
                $s = $loading->subject;
                return (float)($s->total_hrs ?? (($s->total_lec_hrs ?? 0) + ($s->total_lab_hrs ?? 0)));
            });

            $potentialTotalLoad = $currentLoadUnits + $newSubjectUnits;
            if ($potentialTotalLoad > $totalAllowedLoad) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Load Limit Exceeded: Adding this subject ({$newSubjectUnits} units) will result in {$potentialTotalLoad} units which exceeds max {$totalAllowedLoad}."
                ], 422);
            }
            
            // 2. Schedule Flattening - Convert schedules + pairedDays into a single list of daily schedules
            $schedulesToProcess = [];

            foreach ($request->schedules as $sched) {
                $type = strtoupper($sched['type']);
                $roomId = $sched['roomId'];
                $time = $sched['time'];

                // Add the MAIN day schedule
                $schedulesToProcess[] = [
                    'type' => $type,
                    'day' => $sched['day'],
                    'time' => $time,
                    'roomId' => $roomId,
                ];

                // Add the PAIRED days schedules
                $pairedDays = $sched['pairedDays'] ?? [];
                foreach ($pairedDays as $pairedDay) {
                    // Prevent adding the same day twice
                    if (strtolower($pairedDay) === strtolower($sched['day'])) continue; 
                    
                    $schedulesToProcess[] = [
                        'type' => $type,
                        'day' => $pairedDay,
                        'time' => $time,
                        'roomId' => $roomId,
                    ];
                }
            }

            // If no schedules to process after flattening (shouldn't happen with min:1 validation, but as safeguard)
            if (empty($schedulesToProcess)) {
                 DB::rollBack();
                 return response()->json([
                    'success' => false,
                    'message' => "No schedules generated for assignment."
                ], 422);
            }

            $createdSchedules = [];
            $validatedSchedules = []; // normalized already-processed schedules for intra-request checks
            $errors = []; // structured errors keyed by schedule type ('LEC'|'LAB')

            // Build map of already-assigned subject codes per day for this faculty (No change)
            $existingAssignments = FacultyLoading::join('subjects', 'faculty_loadings.subject_id', '=', 'subjects.id')
                ->where('faculty_id', $facultyId)
                ->get(['faculty_loadings.day as day', 'subjects.subject_code as subject_code']);

            $existingSubjectCodesByDay = [];
            foreach ($existingAssignments as $ea) {
                $d = $ea->day;
                $code = strtoupper(trim($ea->subject_code ?? ''));
                if ($d === null || $code === '') continue;
                if (!isset($existingSubjectCodesByDay[$d])) $existingSubjectCodesByDay[$d] = [];
                if (!in_array($code, $existingSubjectCodesByDay[$d])) {
                    $existingSubjectCodesByDay[$d][] = $code;
                }
            }
            $assignedSubjectCodesPerDay = $existingSubjectCodesByDay;


            // 3. Main Conflict and Creation Loop (Looping over $schedulesToProcess)
            foreach ($schedulesToProcess as $index => $sched) {
                $type = $sched['type']; // 'LEC' or 'LAB'
                $day = $sched['day'];
                $timeStr = $sched['time']; // e.g., "08:00-10:00"

                // --- Time Parsing and Validation ---
                $parts = explode('-', $timeStr);
                if (count($parts) !== 2) {
                    $errors[$type] = ($errors[$type] ?? '') . "Invalid time format ({$timeStr}) for schedule on {$day}. ";
                    continue;
                }

                $startRaw = trim($parts[0]);
                $endRaw = trim($parts[1]);

                try {
                    $start = Carbon::parse($startRaw);
                    $end = Carbon::parse($endRaw);
                } catch (\Exception $ex) {
                    $errors[$type] = ($errors[$type] ?? '') . "Invalid time values ({$timeStr}) for schedule on {$day}. ";
                    continue;
                }

                if ($end->lessThanOrEqualTo($start)) {
                    $errors[$type] = ($errors[$type] ?? '') . "End time must be after start time ({$timeStr}) for schedule on {$day}. ";
                    continue;
                }

                $startTime = $start->format('H:i:s');
                $endTime = $end->format('H:i:s');

                $scheduleData = [
                    'faculty_id' => $facultyId,
                    'subject_id' => $subjectId,
                    'room_id'    => $sched['roomId'],
                    'type'       => $type,
                    'day'        => $day,
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
                    '_time_slot' => "{$startTime}-{$endTime}", // For cleaner intra-request error reporting
                ];

                $hasConflict = false;

                // Quick DB/intra-request check: ensure the same subject code isn't already assigned on this day
                $subjectCodeUpper = strtoupper(trim($subject->subject_code ?? ''));
                if (!empty($subjectCodeUpper) && isset($assignedSubjectCodesPerDay[$day]) && in_array($subjectCodeUpper, $assignedSubjectCodesPerDay[$day])) {
                    $errors[$type] = ($errors[$type] ?? '') . "Subject '{$subject->subject_code}' is already assigned to this faculty on {$day}. ";
                    $hasConflict = true;
                }

                // Intra-request conflict (with already validatedSchedules)
                foreach ($validatedSchedules as $vs) {
                    // Only check intra-request conflicts if the schedule is NOT the subject code duplicate (already handled)
                    if ($vs['day'] === $day && empty($vs['_has_error'])) {
                        if ($startTime < $vs['end_time'] && $endTime > $vs['start_time']) {
                            // Conflict detected. Report against the current type.
                            $errors[$type] = ($errors[$type] ?? '') . "Conflict with another selected schedule on {$day} ({$vs['_time_slot']}). ";
                            $hasConflict = true;
                        }
                    }
                }

                // DB check: faculty conflicts
                $facultyConflict = FacultyLoading::where('faculty_id', $facultyId)
                    ->where('day', $day)
                    ->where(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '<', $endTime)
                            ->where('end_time', '>', $startTime);
                    })
                    ->exists();

                if ($facultyConflict) {
                    $errors[$type] = ($errors[$type] ?? '') . "Faculty is already assigned a class on {$day} from {$startRaw} to {$endRaw}. ";
                    $hasConflict = true;
                }

                // DB check: room conflicts
                $roomConflict = FacultyLoading::where('room_id', $sched['roomId'])
                    ->where('day', $day)
                    ->where(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '<', $endTime)
                            ->where('end_time', '>', $startTime);
                    })
                    ->exists();

                if ($roomConflict) {
                    $room = Room::find($sched['roomId']);
                    $roomNumber = $room ? ($room->roomNumber ?? $room->name ?? $room->id) : $sched['roomId'];
                    $errors[$type] = ($errors[$type] ?? '') . "Room {$roomNumber} is already occupied on {$day} from {$startRaw} to {$endRaw}. ";
                    $hasConflict = true;
                }

                // Room availability containment
                $room = Room::with(['availabilities' => function($q) use ($day) {
                    $q->where('day', $day);
                }])->find($sched['roomId']);

                if (!$room) {
                    $errors[$type] = ($errors[$type] ?? '') . "Room not found (id: {$sched['roomId']}) on {$day}. ";
                    $hasConflict = true;
                } else {
                    $fits = false;
                    foreach ($room->availabilities as $avail) {
                        $aStart = Carbon::parse($avail->start_time ?? $avail->start)->format('H:i:s');
                        $aEnd = Carbon::parse($avail->end_time ?? $avail->end)->format('H:i:s');
                        if ($startTime >= $aStart && $endTime <= $aEnd) {
                            $fits = true;
                            break;
                        }
                    }
                    if (!$fits) {
                        $roomNumber = $room->roomNumber ?? $room->name ?? $room->id;
                        $errors[$type] = ($errors[$type] ?? '') . "Room {$roomNumber} is not available on {$day} from {$startRaw} to {$endRaw}. ";
                        $hasConflict = true;
                    }
                }

                // If this schedule accumulated NO errors, add to validatedSchedules for final creation
                if (!$hasConflict) {
                    $validatedSchedules[] = $scheduleData;
                    
                    // Record the assigned subject code for this day for subsequent intra-request checks
                    if (!empty($subjectCodeUpper)) {
                        if (!isset($assignedSubjectCodesPerDay[$day])) $assignedSubjectCodesPerDay[$day] = [];
                        if (!in_array($subjectCodeUpper, $assignedSubjectCodesPerDay[$day])) {
                            $assignedSubjectCodesPerDay[$day][] = $subjectCodeUpper;
                        }
                    }
                } else {
                    // Mark as error-full for intra-request cross-referencing only
                    $validatedSchedules[] = $scheduleData + ['_has_error' => true];
                }
            } // end foreach $schedulesToProcess

            if (!empty($errors)) {
                // Normalize messages and add type prefixes for clarity
                $formatted = [];
                foreach ($errors as $k => $msg) {
                    $label = strtoupper($k) === 'LEC' ? 'Lecture conflict: ' : (strtoupper($k) === 'LAB' ? 'Laboratory conflict: ' : '');
                    // Use a clean and concise message format
                    $formatted[$k] = $label . rtrim(trim($msg), '. ');
                }

                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule conflicts detected. Please review the errors below.',
                    'errors' => $formatted
                ], 422);
            }

            // No errors -> create all successfully validated schedules
            foreach ($validatedSchedules as $s) {
                if (!empty($s['_has_error'])) continue;
                $createdSchedules[] = FacultyLoading::create([
                    'faculty_id' => $s['faculty_id'],
                    'subject_id' => $s['subject_id'],
                    'room_id'    => $s['room_id'],
                    'type'       => $s['type'],
                    'day'        => $s['day'],
                    'start_time' => $s['start_time'],
                    'end_time'   => $s['end_time'],
                ]);
            }

            DB::commit();

            // The main assignment is successful, but let's check if the LEC/LAB parts were actually created
            if (empty($createdSchedules)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Assignment failed: No schedules were created despite no reported conflicts. Internal error.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Subject assigned successfully, including all paired days.',
                'data' => $createdSchedules
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Faculty Assignment Error: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
            return response()->json([
                'success' => false,
                'message' => 'Server error during assignment. Please try again.',
            ], 500);
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
        // Eager load relationships: 'faculty.user', 'subject', 'room', and 'schedules'.
        $facultyLoadings = FacultyLoading::with('faculty.user', 'subject', 'room', 'schedules')
            // Filter the results: only include FacultyLoadings if the related faculty's status is 1
            ->whereHas('faculty', function ($query) {
                $query->where('status', 0);
            })
            ->get(); 
        
        return response()->json([
            'success' => true,
            "facultyLoading" => $facultyLoadings,
        ], 200);

    }

    public function getClassScheduleReports()
    {
        $classSchedule = CreateSchedule::with('facultyLoading.faculty.user', 'facultyLoading.subject','facultyLoading.room','program')->get();

        return response()->json([
            'success' => true,
            "classSchedule" => $classSchedule,
        ], 200);
    }
}
