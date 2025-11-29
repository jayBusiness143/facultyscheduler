<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\FacultyLoadingController;

// Route to get the authenticated user's information
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Route for user registration
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('guest')
    ->name('register');

// Route for user login
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

// Route for user logout
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('logout');


// --- IGNORE ---
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/add-program', [CurriculumController::class, 'add_program'])->name('add-program');
        Route::get('/program', [CurriculumController::class, 'get_program'])->name('get-program');
        Route::get('/department-program', [CurriculumController::class, 'department_program'])->name('department-program');
        Route::post('/edit-program/{id}', [CurriculumController::class, 'edit_program'])->name('edit-program');
        Route::post('/archive-program/{id}', [CurriculumController::class, 'archive_program'])->name('archive-program');
        Route::post('/restore-program/{id}', [CurriculumController::class, 'restore']);
        
        Route::put('/semesters/{semester}/status', [CurriculumController::class, 'updateStatus']);
        Route::post('/semester-with-subjects', [CurriculumController::class, 'add_semester_with_subjects'])->name('semester.with-subjects.add');
        Route::get('/semester-with-subjects', [CurriculumController::class, 'get_semester_with_subjects'])->name('semester.with-subjects.get');
        Route::put('/semesters/{semester}/rename', [CurriculumController::class, 'rename']);

        Route::apiResource('faculties', FacultyController::class);
        Route::post('/faculties/{id}/activate', [FacultyController::class, 'activate']);
        Route::post('/faculties/{faculty}/availability', [FacultyController::class, 'setAvailability']);
        Route::get('/faculties/{faculty}/availability', [FacultyController::class, 'getAvailability']);

        Route::get('/get-subjects', [SubjectController::class, 'get_subjects']);
        Route::post('/semesters/{semester}/subjects', [SubjectController::class, 'store']);
        Route::put('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);

                // Get availabilities for all rooms
        Route::get('/rooms/availabilities', [RoomController::class, 'getAllRoomsAvailability']);
        Route::apiResource('rooms', RoomController::class);
        Route::post('/rooms/{room}/availabilities', [RoomController::class, 'storeRoomAvailability'])->whereNumber('room');
        Route::get('/rooms/{room}/availabilities', [RoomController::class, 'getRoomAvailability'])->whereNumber('room');
        Route::delete('/availabilities/{availability}', [RoomController::class, 'destroyRoomAvailability']);

       Route::get('/dashboard/today-statistics', [FacultyLoadingController::class, 'getTodayScheduleStatistics']);
        Route::get('/faculty-loading/{facultyId}/schedules', [FacultyLoadingController::class, 'getFacultySchedule']);
        Route::post('/faculty-loading/assign', [FacultyLoadingController::class, 'assignSubject']);
    });
