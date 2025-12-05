<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Faculty; // Assuming Faculty model maps to 'faculties' table
use App\Models\FacultyLoading; // Assuming FacultyLoading maps to 'faculty_loadings'
use App\Models\Subject; // Assuming Subject model maps to 'subjects'

class ReportController extends Controller
{
    /**
     * Retrieves the key performance indicators for the reports page.
     * Includes Total Faculty, Assigned Subjects, and Total Units Loaded.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getKpiData(Request $request)
    {
        // KPI 1: Total Active Faculty
        // Counts all faculty records where status is '0' (active) from the 'faculties' table.
        $totalFaculty = Faculty::where('status', 0)->count();

        // KPI 2: Total Assigned Subjects (Distinct Subjects)
        // Counts the number of unique subjects that have at least one entry 
        // in the 'faculty_loadings' table.
        $assignedSubjectsCount = FacultyLoading::distinct('subject_id')->count('subject_id');
        
        // KPI 3: Total Units Loaded
        // Calculates the sum of 'total_units' for ALL distinct subjects that 
        // have been assigned (are in the 'faculty_loadings' table).
        // This is the most accurate way to get the total teaching load units.
        $totalUnitsLoaded = Subject::whereIn('id', function ($query) {
            $query->select('subject_id')
                  ->from('faculty_loadings')
                  ->distinct();
        })->sum('total_units');


        $kpis = [
            'totalFaculty' => $totalFaculty,
            'assignedSubjects' => $assignedSubjectsCount,
            'totalUnitsLoaded' => (int) $totalUnitsLoaded, // Cast to int for clean API response
        ];

        return response()->json([
            'success' => true,
            'data' => $kpis,
            'message' => 'KPI data retrieved successfully.'
        ], 200);
    }
}