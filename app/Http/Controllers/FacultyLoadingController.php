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

        DB::beginTransaction();

        try {
            $facultyId = $request->facultyId;
            $subjectId = $request->subjectId;

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

            $createdSchedules = [];
            $validatedSchedules = []; // normalized already-processed schedules for intra-request checks
            $errors = []; // structured errors keyed by schedule type ('LEC'|'LAB')

            foreach ($request->schedules as $index => $sched) {
                $type = strtoupper($sched['type']); // 'LEC' or 'LAB'
                $day = $sched['day'];

                // Parse time
                $parts = explode('-', $sched['time']);
                if (count($parts) !== 2) {
                    $errors[$type] = ($errors[$type] ?? '') . "Invalid time format for schedule #".($index + 1).". ";
                    continue;
                }

                $startRaw = trim($parts[0]);
                $endRaw = trim($parts[1]);

                try {
                    $start = Carbon::parse($startRaw);
                    $end = Carbon::parse($endRaw);
                } catch (\Exception $ex) {
                    $errors[$type] = ($errors[$type] ?? '') . "Invalid time values for schedule #".($index + 1).": {$sched['time']}. ";
                    continue;
                }

                if ($end->lessThanOrEqualTo($start)) {
                    $errors[$type] = ($errors[$type] ?? '') . "End time must be after start time for schedule #".($index + 1).". ";
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
                ];

                // Intra-request conflict (with already validatedSchedules)
                foreach ($validatedSchedules as $vs) {
                    if ($vs['day'] === $day) {
                        if ($startTime < $vs['end_time'] && $endTime > $vs['start_time']) {
                            // mark both involved types (if different) or the same type
                            $errors[$type] = ($errors[$type] ?? '') . "Conflict with another selected schedule on {$day} ({$startTime}-{$endTime}). ";
                            // also mark the previously validated schedule type if desired
                            $errors[$vs['type']] = ($errors[$vs['type']] ?? '') . "Conflict with another selected schedule on {$day} ({$vs['start_time']}-{$vs['end_time']}). ";
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
                    $errors[$type] = ($errors[$type] ?? '') . "Faculty is already assigned a class on {$day} from {$startTime} to {$endTime}. ";
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
                    $errors[$type] = ($errors[$type] ?? '') . "Room {$roomNumber} is already occupied on {$day} from {$startTime} to {$endTime}. ";
                }

                // Room availability containment
                $room = Room::with(['availabilities' => function($q) use ($day) {
                    $q->where('day', $day);
                }])->find($sched['roomId']);

                if (!$room) {
                    $errors[$type] = ($errors[$type] ?? '') . "Room not found (id: {$sched['roomId']}). ";
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
                        $errors[$type] = ($errors[$type] ?? '') . "Room {$roomNumber} is not available on {$day} from {$startTime} to {$endTime}. ";
                    }
                }

                // If this schedule accumulated no errors so far, add to validatedSchedules to be created
                if (empty($errors[$type])) {
                    $validatedSchedules[] = $scheduleData;
                } else {
                    // still add to validatedSchedules so later intra-request checks can reference it,
                    // but mark that it had errors (do not create later)
                    $validatedSchedules[] = $scheduleData + ['_has_error' => true];
                }
            } // end foreach schedules

            if (!empty($errors)) {
                // Normalize messages and add type prefixes for clarity
                $formatted = [];
                foreach ($errors as $k => $msg) {
                    $label = strtoupper($k) === 'LEC' ? 'Lecture conflict: ' : (strtoupper($k) === 'LAB' ? 'Laboratory conflict: ' : '');
                    $formatted[$k] = $label . trim($msg);
                }

                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule conflicts detected',
                    'errors' => $formatted
                ], 422);
            }

            // No errors -> create all validated schedules (those without _has_error)
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

            return response()->json([
                'success' => true,
                'message' => 'Subject assigned successfully.',
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
