<?php

namespace App\Http\Controllers;

use App\Models\Semester;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    public function filterSubjects(Request $request)
{
    $validated = $request->validate([
        'subject' => ['nullable', 'string', 'max:255'],
        'program_id' => ['nullable', 'integer', 'exists:programs,id'],
        'department' => ['nullable', 'string', 'max:255'],
    ]);

    $searchTerm = trim($validated['subject'] ?? '');
    $programId = $validated['program_id'] ?? null;
    $department = trim($validated['department'] ?? '');

    $subjects = Subject::query()
        ->when($searchTerm !== '', function ($query) use ($searchTerm) {
            $query->where(function ($searchQuery) use ($searchTerm) {
                $searchQuery->where('des_title', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('subject_code', 'LIKE', "%{$searchTerm}%");
            });
        })
        ->whereHas('semester', function ($query) use ($programId, $department) {
            $query->where('status', 0);

            if ($programId) {
                $query->where('program_id', $programId);
            }

            if ($department !== '') {
                $query->whereHas('program', function ($programQuery) use ($department) {
                    $programQuery->where('program_name', $department)
                        ->orWhere('abbreviation', $department);
                });
            }
        })
        ->with(['semester.program'])
        ->orderBy('subject_code')
        ->get();

    return response()->json([
        'success' => true,
        'data' => $subjects,
        'subject' => $subjects,
    ], 200);
}

    public function get_subjects()
    {
        // Return only subjects whose related semester is active.
        $subjects = Subject::whereHas('semester', function ($query) {
            $query->where('status', 1);
        })->with(['semester.program'])->get();

        return response()->json([
            'subject' => $subjects
        ], 200);
    }
    /**
     * Add a new subject to a specific semester.
     */


    public function store(Request $request, Semester $semester)
    {
        $validatedData = $request->validate([
            'subject_code' => [
                'required',
                'string',
                'max:255',
                // Sigurohon nga ang subject code kay unique para lang sa sulod aning semester
                Rule::unique('subjects')->where('semester_id', $semester->id),
            ],
            'des_title' => ['required', 'string', 'max:255'],
            'total_units' => ['required', 'integer', 'min:0'],
            'lec_units' => ['required', 'integer', 'min:0'],
            'lab_units' => ['required', 'integer', 'min:0'],
            'total_hrs' => ['required', 'integer', 'min:0'],
            'total_lec_hrs' => ['required', 'integer', 'min:0'],
            'total_lab_hrs' => ['required', 'integer', 'min:0'],
            'pre_requisite' => ['nullable', 'string', 'max:255'],
        ]);

        // Gamiton ang relationship para awtomatik ma-set ang semester_id
        $subject = $semester->subjects()->create($validatedData);

        return response()->json([
            'message' => 'Subject added successfully!',
            'subject' => $subject
        ], 201); // 201 Created status
    }

    /**
     * Update an existing subject.
     */
    public function update(Request $request, Subject $subject)
    {
        $validatedData = $request->validate([
            'subject_code' => [
                'required',
                'string',
                'max:255',
                // Parehas nga validation, pero i-ignore ang iyang kaugalingon nga ID
                Rule::unique('subjects')->where('semester_id', $subject->semester_id)->ignore($subject->id),
            ],
            'des_title' => ['required', 'string', 'max:255'],
            'total_units' => ['required', 'integer', 'min:0'],
            'lec_units' => ['required', 'integer', 'min:0'],
            'lab_units' => ['required', 'integer', 'min:0'],
            'total_hrs' => ['required', 'integer', 'min:0'],
            'total_lec_hrs' => ['required', 'integer', 'min:0'],
            'total_lab_hrs' => ['required', 'integer', 'min:0'],
            'pre_requisite' => ['nullable', 'string', 'max:255'],
        ]);

        $subject->update($validatedData);

        return response()->json([
            'message' => 'Subject updated successfully!',
            'subject' => $subject
        ]);
    }

    /**
     * Delete a subject.
     */
    public function destroy(Subject $subject)
    {
        $subject->delete();

        return response()->json([
            'message' => 'Subject deleted successfully!'
        ]);
    }
}
