<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\CreateSchedule;
use App\Models\FacultyLoading;
use Carbon\Carbon;

class DashboardController extends Controller
{
    // Total hours considered for max load in the frontend (used for calculation)
    private const MAX_LOAD_HOURS = 20; 

    /**
     * Fetch all KPI data for the dashboard.
     * Corresponds to the data needed by <KpiCards />
     */
    public function getKpiData()
    {
        try {
            // ... (No changes needed here, logic is sound)
            $totalFaculty = Faculty::where('status', 0)->count(); 
            $totalClasses = FacultyLoading::count();

            // 3. Rooms Utilized
            $roomsUtilized = FacultyLoading::distinct('room_id')->count('room_id');

            // 4. Avg. Faculty Load
            $totalLoadUnits = Faculty::where('status', 0)->sum('t_load_units');
            $avgFacultyLoad = $totalFaculty > 0 ? round($totalLoadUnits / $totalFaculty, 1) : 0;
            
            $kpiData = [
                ['title' => "Total Faculty", 'value' => $totalFaculty, 'icon' => 'Users'],
                ['title' => "Total Classes", 'value' => $totalClasses, 'icon' => 'BookCopy'],
                ['title' => "Rooms Utilized", 'value' => $roomsUtilized, 'icon' => 'Building2'],
                ['title' => "Avg. Faculty Load", 'value' => "{$avgFacultyLoad}h", 'icon' => 'BarChart3'],
            ];

            return response()->json(['success' => true, 'data' => $kpiData]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch KPI data.', 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * Fetch data for the weekly overview chart and class breakdown.
     * Corresponds to the data needed by <WeeklyOverviewChart /> and <ClassBreakdown />
     */
    public function getWeeklyScheduleData()
    {
        try {
            // Use assigned faculty loadings so the dashboard reflects faculty assignments immediately,
            // even before a loading is placed into a section class schedule.
            $loadings = FacultyLoading::with([
                'subject',
                'room',
                'faculty.user',
                'schedules'
            ])->get();

            $dayMap = [
                'Monday' => 'MON', 'Tuesday' => 'TUE', 'Wednesday' => 'WED',
                'Thursday' => 'THU', 'Friday' => 'FRI', 'Saturday' => 'SAT'
            ];

            $weeklyOverviewData = [
                'MON' => 0, 'TUE' => 0, 'WED' => 0, 'THU' => 0, 'FRI' => 0, 'SAT' => 0
            ];

            $allClasses = [];

            foreach ($loadings as $loading) {
                $dayKey = $dayMap[$loading->day] ?? null;
                if (!$dayKey) {
                    continue;
                }

                $weeklyOverviewData[$dayKey]++;

                $start = $loading->start_time ? Carbon::parse($loading->start_time)->format('h:i A') : null;
                $end = $loading->end_time ? Carbon::parse($loading->end_time)->format('h:i A') : null;
                $sections = $loading->schedules->pluck('section')->filter()->unique()->values()->all();

                $allClasses[] = [
                    'id' => $loading->id,
                    'day' => $dayKey,
                    'code' => optional($loading->subject)->subject_code,
                    'title' => optional($loading->subject)->des_title,
                    'time' => ($start && $end) ? ($start . ' - ' . $end) : null,
                    'facultyName' => optional(optional($loading->faculty)->user)->name,
                    'room' => optional($loading->room)->roomNumber,
                    'type' => $loading->type,
                    'section' => count($sections) ? implode(', ', $sections) : null,
                    'isAssignedFacultyLoad' => true,
                ];
            }

            usort($allClasses, function ($a, $b) {
                return strcmp($a['time'] ?? '', $b['time'] ?? '');
            });

            return response()->json([
                'success' => true,
                'weeklyOverview' => $weeklyOverviewData,
                'allClasses' => $allClasses,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch weekly schedule data.', 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * Fetch data for the faculty load chart.
     * Corresponds to the data needed by <FacultyLoadChart />
     */
    public function getFacultyLoadDistribution()
    {
        try {
            $faculties = Faculty::with(['user', 'loadings.subject'])
                ->where('status', 0)
                ->get();

            $loadData = $faculties->map(function ($faculty) {
                $assignedSubjects = $faculty->loadings
                    ->filter(fn ($loading) => $loading->subject)
                    ->unique('subject_id');

                $assignedLoad = $assignedSubjects->sum(function ($loading) {
                    $subject = $loading->subject;
                    return (float) ($subject->total_units ?? $subject->total_hrs ?? 0);
                });

                $baseLimit = (float) ($faculty->t_load_units ?? 0);
                $overloadLimit = (float) ($faculty->overload_units ?? 0);
                $maxLoad = $baseLimit + $overloadLimit;

                return [
                    'name' => optional($faculty->user)->name ?? 'Unnamed Faculty',
                    'load' => $assignedLoad,
                    'maxLoad' => $maxLoad > 0 ? $maxLoad : $baseLimit,
                    'baseLoad' => $baseLimit,
                    'overloadUnits' => $overloadLimit,
                    'assignedSubjects' => $assignedSubjects->count(),
                ];
            })->sortByDesc('load')->values();

            return response()->json(['success' => true, 'data' => $loadData]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch faculty load data.', 'error' => $e->getMessage()], 500);
        }
    }
}