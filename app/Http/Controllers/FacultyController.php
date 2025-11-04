<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Faculty;
use App\Models\Expertise;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FacultyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $facultyActive = Faculty::where('status', 0)
        ->with(['user', 'expertises'])
        ->latest()
        ->get();

        $facultyInactive = Faculty::where('status', 1)
        ->with(['user', 'expertises'])
        ->latest()
        ->get();

        return response()->json([
           'faculties' => $facultyActive,
           'inactive_faculties' => $facultyInactive
        ], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // 1. Validation - gidugang ang 'avatar'
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'role' => ['required', 'integer', Rule::in([0, 1, 2])],
            'designation' => ['required', 'string', Rule::in(['Dean', 'Program Head', 'Faculty'])],
            'department' => 'required|string',
            'deload_units' => 'sometimes|integer|min:0',
            't_load_units' => 'sometimes|integer|min:0',
            'overload_units' => 'sometimes|integer|min:0',
            'expertise' => 'sometimes|array',
            'expertise.*' => 'string|max:255',
            
            // Validation para sa image file. `nullable` nagpasabot nga opsyonal.
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // max:2048 = 2MB
        ]);

        DB::beginTransaction();

        try {
            // 2. I-handle ang file upload (kung naa)
            $avatarPath = null;
            if ($request->hasFile('avatar')) {
                // Create avatars directory if it doesn't exist
                $avatarDirectory = public_path('avatars');
                if (!file_exists($avatarDirectory)) {
                    mkdir($avatarDirectory, 0755, true);
                }

                // Generate unique filename
                $file = $request->file('avatar');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                
                // Move file to public/avatars directory
                $file->move($avatarDirectory, $filename);
                
                // Save the relative path for database
                $avatarPath = 'avatars/' . $filename;
            }

            // 3. I-create ang User
            $user = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'password' => Hash::make('@password123'),
                'role' => $validatedData['role'], 
            ]);

            // 4. I-create ang Faculty uban ang path sa avatar
            $faculty = Faculty::create([
                'user_id' => $user->id,
                'name' => $validatedData['name'],
                'profile_picture' => $avatarPath, // I-save ang path sa database
                'designation' => $validatedData['designation'],
                'department' => $validatedData['department'],
                'deload_units' => $validatedData['deload_units'] ?? 0,
                't_load_units' => $validatedData['t_load_units'] ?? 0,
                'overload_units' => $validatedData['overload_units'] ?? 0,
            ]);

            // 5. I-handle ang Expertises
            if (!empty($validatedData['expertise'])) {
                $expertiseToInsert = [];
                foreach ($validatedData['expertise'] as $expertiseName) {
                    $expertiseToInsert[] = [
                        'faculty_id' => $faculty->id,
                        'list_of_expertise' => trim($expertiseName),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                Expertise::insert($expertiseToInsert);
            }

            DB::commit();

            // I-load ang relationships
            $faculty->load(['user', 'expertises']);

            return response()->json([
                'message' => 'Faculty and User account created successfully!',
                'faculty' => $faculty
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create faculty.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Faculty $faculty) // Gigamit ang Route Model Binding
    {
        return response()->json([
            'faculty' => $faculty->load(['user', 'expertises'])
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Faculty $faculty)
    {
        // 1. Validation - halos pareho sa `store` pero i-ignore ang unique check para sa kasamtangan nga user
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            // I-ignore ang unique rule para sa email sa kasamtangan nga user
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($faculty->user_id)],
            'role' => ['required', 'integer', Rule::in([0, 1, 2])],
            'designation' => ['required', 'string', Rule::in(['Dean', 'Program Head', 'Faculty'])],
            'department' => 'required|string',
            'deload_units' => 'sometimes|integer|min:0',
            't_load_units' => 'sometimes|integer|min:0',
            'overload_units' => 'sometimes|integer|min:0',
            'expertise' => 'sometimes|array',
            'expertise.*' => 'string|max:255',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        DB::beginTransaction();

        try {
            // 2. I-update ang User details
            $user = $faculty->user;
            $user->update([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'role' => $validatedData['role'],
            ]);

            // 3. I-handle ang file upload para sa update (save directly to public/avatars)
            $avatarPath = $faculty->profile_picture; // Pabilinon ang daan nga path by default
            if ($request->hasFile('avatar')) {
                // Kung naay daan nga picture, i-delete kini gikan sa public folder
                if ($avatarPath) {
                    $oldPath = public_path($avatarPath);
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                // I-save ang bag-ong picture diretso sa public/avatars
                $avatarDirectory = public_path('avatars');
                if (!file_exists($avatarDirectory)) {
                    mkdir($avatarDirectory, 0755, true);
                }

                $file = $request->file('avatar');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move($avatarDirectory, $filename);
                $avatarPath = 'avatars/' . $filename;
            }

            // 4. I-update ang Faculty details
            $faculty->update([
                'name' => $validatedData['name'],
                'profile_picture' => $avatarPath,
                'designation' => $validatedData['designation'],
                'department' => $validatedData['department'],
                'deload_units' => $validatedData['deload_units'] ?? $faculty->deload_units,
                't_load_units' => $validatedData['t_load_units'] ?? $faculty->t_load_units,
                'overload_units' => $validatedData['overload_units'] ?? $faculty->overload_units,
            ]);

            // 5. I-update ang Expertises gamit ang `sync`
            if (isset($validatedData['expertise'])) {
                // I-delete tanan daan nga expertise aning faculty
                $faculty->expertises()->delete();

                // I-insert ang mga bag-o
                $expertiseToInsert = [];
                foreach ($validatedData['expertise'] as $expertiseName) {
                     $expertiseToInsert[] = [
                        'list_of_expertise' => trim($expertiseName),
                    ];
                }
                if (!empty($expertiseToInsert)) {
                    $faculty->expertises()->createMany($expertiseToInsert);
                }
            }

            DB::commit();

            // I-load usab ang relationships para makuha ang pinakabag-ong data
            $faculty->load(['user', 'expertises']);

            return response()->json([
                'message' => 'Faculty updated successfully!',
                'faculty' => $faculty
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update faculty.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Faculty $faculty)
    {
        try {
            // Usbon lang ang status ngadto sa 1 (inactive)
            $faculty->status = 1; 
            $faculty->save();

            return response()->json([
                'message' => 'Faculty moved to archives successfully!'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to archive faculty.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function activate(Faculty $id)
    {
        try {
            $id->status = 0; // 0 = Active
            $id->save();
            return response()->json(['message' => 'Faculty activated successfully!'], 200); 
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to activate faculty.', 'error' => $e->getMessage()], 500);
        }
    }
}
