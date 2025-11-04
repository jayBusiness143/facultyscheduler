<?php

namespace App\Http\Controllers;

use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule; 
use App\Models\Semester;
use App\Models\Subject;
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Arr;         

class CurriculumController extends Controller
{
    /**
     * Mag-add ng bagong program sa database.
     */
    public function add_program(Request $request)
    {
        // Ang validation rules
        $rules = [
            'program_name' => 'required|string|max:255',
            'abbreviation' => 'required|string|max:20',
            'year_from' => [
                'required',
                'digits:4',
                // FIX: I-check lang ang uniqueness sa mga active programs (status = 0)
                Rule::unique('programs')->where(function ($query) use ($request) {
                    return $query->where('program_name', $request->program_name)
                                 ->where('year_to', $request->year_to)
                                 ->where('status', 0); // I-check lang ang mga active records
                }),
            ],
            'year_to' => 'required|digits:4|gt:year_from', 
        ];

        // Ang custom error message para sa unique rule
        $messages = [
            'year_from.unique' => 'An active program with the same name and academic year already exists.',
        ];

        // I-validate ang request gamit ang rules ug custom messages
        $validatedData = $request->validate($rules, $messages);

        try {
            // Ang `status` kay awtomatikong ma-set sa `0` (default) base sa migration
            $program = Program::create($validatedData);

            return response()->json([
                'message' => 'Program added successfully!',
                'program' => $program
            ], 201); 

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to add program.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function add_semester_with_subjects(Request $request)
    {
        // Step 1: I-validate ang lahat ng data, kasama ang nested array ng subjects
        $validatedData = $request->validate([
            // --- Semester Details Validation ---
            'program_id' => 'required|integer|exists:programs,id',
            'year_level' => [
                'required', 'string', 'max:50',
                Rule::unique('semesters')->where(function ($query) use ($request) {
                    return $query->where('program_id', $request->program_id)
                                 ->where('semester_level', $request->semester_level);
                }),
            ],
            'semester_level' => 'required|string|max:50',

            // --- Subjects Array Validation ---
            'subjects' => 'required|array|min:1', // Dapat may at least isang subject
            // I-validate ang bawat object sa loob ng 'subjects' array
            'subjects.*.subject_code' => 'required|string|max:50',
            'subjects.*.des_title' => 'required|string|max:255',
            'subjects.*.total_units' => 'required|numeric|min:0',
            'subjects.*.lec_units' => 'required|numeric|min:0',
            'subjects.*.lab_units' => 'required|numeric|min:0',
            'subjects.*.total_hrs' => 'nullable|numeric|min:0',
            'subjects.*.total_lec_hrs' => 'nullable|numeric|min:0',
            'subjects.*.total_lab_hrs' => 'nullable|numeric|min:0',
            'subjects.*.pre_requisite' => 'nullable|string|max:255',
        ]);

        // Simulan ang Database Transaction
        DB::beginTransaction();

        try {
            // Step 2: I-create ang Semester
            // Gamit ang Arr::except, kukunin natin ang data para lang sa semester
            $semesterData = Arr::except($validatedData, ['subjects']);
            $semester = Semester::create($semesterData);

            // Step 3: I-loop at i-create ang bawat Subject na nakaugnay sa bagong Semester
            foreach ($validatedData['subjects'] as $subjectData) {
                // I-check kung unique ba ang subject_code sa loob ng semester na ito
                $isDuplicate = Subject::where('semester_id', $semester->id)
                                      ->where('subject_code', $subjectData['subject_code'])
                                      ->exists();
                
                if ($isDuplicate) {
                    // Kung may duplicate, mag-throw ng error para ma-trigger ang rollback
                    throw new \Exception("Duplicate subject code '{$subjectData['subject_code']}' found for this semester.");
                }

                // Ensure integer fields that are required by the DB have defaults
                $subjectData = array_merge([
                    'total_hrs' => 0,
                    'total_lec_hrs' => 0,
                    'total_lab_hrs' => 0,
                    'pre_requisite' => $subjectData['pre_requisite'] ?? null,
                ], $subjectData);

                // Cast numeric values to int to avoid SQL errors
                $subjectData['total_units'] = isset($subjectData['total_units']) ? (int) $subjectData['total_units'] : 0;
                $subjectData['lec_units'] = isset($subjectData['lec_units']) ? (int) $subjectData['lec_units'] : 0;
                $subjectData['lab_units'] = isset($subjectData['lab_units']) ? (int) $subjectData['lab_units'] : 0;
                $subjectData['total_hrs'] = isset($subjectData['total_hrs']) ? (int) $subjectData['total_hrs'] : 0;
                $subjectData['total_lec_hrs'] = isset($subjectData['total_lec_hrs']) ? (int) $subjectData['total_lec_hrs'] : 0;
                $subjectData['total_lab_hrs'] = isset($subjectData['total_lab_hrs']) ? (int) $subjectData['total_lab_hrs'] : 0;

                // Gamitin ang relationship para i-create ang subject
                $semester->subjects()->create($subjectData);
            }

            // Kung naging successful ang lahat, i-commit ang changes sa database
            DB::commit();

            // I-load ang bagong semester kasama ang mga subjects para sa response
            $semester->load('subjects');

            return response()->json([
                'message' => 'Semester and subjects added successfully!',
                'semester' => $semester
            ], 201);

        } catch (\Exception $e) {
            // Kung may naganap na error, i-rollback ang lahat ng changes
            DB::rollBack();

            // Ibalik ang error response
            return response()->json([
                'message' => 'Failed to add semester and subjects.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_program()
    {
        try {
            $programs = Program::get()->whereIn('status', 0);

            return response()->json([
                'programs' => $programs
            ], 200); 

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve programs.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function department_program()
    {
        try {
            $programs = Program::get();

            return response()->json([
                'programs' => $programs
            ], 200); 

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve programs.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function edit_program($id , Request $request)
    {
        $validatedData = $request->validate([
            'program_name' => 'required|string|max:255',
            'abbreviation' => 'required|string|max:20',
            'year_from' => [
                'required',
                'digits:4',
                Rule::unique('programs')->where(function ($query) use ($request, $id) {
                    return $query->where('program_name', $request->program_name)
                                 ->where('year_to', $request->year_to)
                                 ->where('id', '!=', $id);
                }),
            ],
            'year_to' => 'required|digits:4|gt:year_from', 
        ]);

        $validator = \Validator::make($request->all(), $validatedData);
        $validator->setCustomMessages([
            'year_from.unique' => 'The academic year from ' . $request->year_from . ' to ' . $request->year_to . ' for this program already exists.',
        ]);

        try {
            $program = Program::findOrFail($id);
            $program->update($validatedData);

            return response()->json([
                'message' => 'Program updated successfully!',
                'program' => $program
            ], 200); 

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update program.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function delete_program(Program $id) // Gamit og Route Model Binding
    {
        try {
            // Ang Program::findOrFail($id) dili na kinahanglan
            $id->status = 1; 
            $id->save();

            return response()->json([
                'message' => 'Program moved to archives successfully!'
            ], 200); 

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete program.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get semesters with their subjects for a specific program
     */
    public function get_semester_with_subjects(Request $request)
    {
        try {
            // Validate the program_id from the request
            $validatedData = $request->validate([
                'program_id' => 'required|integer|exists:programs,id'
            ]);

            // Get all semesters with their subjects for the specified program
            $semesters = Semester::where('program_id', $validatedData['program_id'])
                ->with(['subjects' => function($query) {
                    $query->orderBy('subject_code');
                }])
                ->orderByRaw("CASE 
                    WHEN year_level = '1st Year' THEN 1
                    WHEN year_level = '2nd Year' THEN 2
                    WHEN year_level = '3rd Year' THEN 3
                    WHEN year_level = '4th Year' THEN 4
                    ELSE 5 END")
                ->orderByRaw("CASE 
                    WHEN semester_level = 'First' THEN 1
                    WHEN semester_level = 'Second' THEN 2
                    WHEN semester_level = 'Summer' THEN 3
                    ELSE 4 END")
                ->get();

            return response()->json([
                'message' => 'Semesters with subjects retrieved successfully!',
                'semesters' => $semesters
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve semesters and subjects.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
