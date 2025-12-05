<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\CreateSchedule;
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
            $totalClasses = CreateSchedule::count();

            // 3. Rooms Utilized
            $roomsUtilized = CreateSchedule::join('faculty_loadings', 'create_schedules.faculty_loading_id', '=', 'faculty_loadings.id')
                                         ->distinct()
                                         ->count('faculty_loadings.room_id');

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
            // Eager-load relationships via `with()` to avoid manual joins
            $schedules = CreateSchedule::with([
                'facultyLoading.subject',
                'facultyLoading.room',
                'facultyLoading.faculty.user'
            ])->get();

            $dayMap = [
                'Monday' => 'MON', 'Tuesday' => 'TUE', 'Wednesday' => 'WED',
                'Thursday' => 'THU', 'Friday' => 'FRI', 'Saturday' => 'SAT'
            ];

            $weeklyOverviewData = [
                'MON' => 0, 'TUE' => 0, 'WED' => 0, 'THU' => 0, 'FRI' => 0, 'SAT' => 0
            ];

            $allClasses = [];

            foreach ($schedules as $schedule) {
                $fl = $schedule->facultyLoading;
                if (!$fl) {
                    continue; // skip if related faculty loading is missing
                }

                $dayKey = $dayMap[$fl->day] ?? null;
                if ($dayKey) {
                    $weeklyOverviewData[$dayKey]++;
                    
                    // **********************************************
                    // FIX: Change 'H:i' (24hr) to 'h:i A' (12hr with AM/PM)
                    // **********************************************
                    $start = $fl->start_time ? Carbon::parse($fl->start_time)->format('h:i A') : null;
                    $end = $fl->end_time ? Carbon::parse($fl->end_time)->format('h:i A') : null;

                    $allClasses[] = [
                        'id' => $schedule->id,
                        'day' => $dayKey,
                        'code' => optional($fl->subject)->subject_code,
                        'title' => optional($fl->subject)->des_title,
                        'time' => ($start && $end) ? ($start . ' - ' . $end) : null,
                        'facultyName' => optional(optional($fl->faculty)->user)->name,
                        'room' => optional($fl->room)->roomNumber
                    ];
                }
            }

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
            // Fetch faculties and their total load units ('t_load_units')
            $facultyLoads = Faculty::select('users.name', 'faculties.t_load_units')
                ->join('users', 'faculties.user_id', '=', 'users.id')
                ->where('faculties.status', 0) // Only active faculty
                ->orderBy('faculties.t_load_units', 'desc')
                ->get();
            
            $loadData = $facultyLoads->map(function ($faculty) {
                return [
                    'name' => $faculty->name,
                    'load' => $faculty->t_load_units,
                    // The frontend component handles the calculation based on MAX_LOAD
                ];
            });

            return response()->json(['success' => true, 'data' => $loadData]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch faculty load data.', 'error' => $e->getMessage()], 500);
        }
    }
}