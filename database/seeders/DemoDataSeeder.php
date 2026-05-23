<?php

namespace Database\Seeders;

use App\Models\CreateSchedule;
use App\Models\Expertise;
use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\FacultyLoading;
use App\Models\Program;
use App\Models\Room;
use App\Models\RoomAvailability;
use App\Models\Semester;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed non-user demo data for local/dev testing.
     */
    public function run(): void
    {
        $bsit = Program::updateOrCreate(
            ['abbreviation' => 'BSIT'],
            [
                'program_name' => 'Bachelor of Science in Information Technology',
                'year_from' => 2025,
                'year_to' => 2026,
                'status' => 0,
            ]
        );

        $bscs = Program::updateOrCreate(
            ['abbreviation' => 'BSCS'],
            [
                'program_name' => 'Bachelor of Science in Computer Science',
                'year_from' => 2025,
                'year_to' => 2026,
                'status' => 0,
            ]
        );

        $bsitSem1 = Semester::updateOrCreate(
            [
                'program_id' => $bsit->id,
                'year_level' => '1st Year',
                'semester_level' => '1st Semester',
            ],
            [
                'status' => 1,
                'start_date' => '2026-06-01',
                'end_date' => '2026-10-15',
            ]
        );

        $bscsSem1 = Semester::updateOrCreate(
            [
                'program_id' => $bscs->id,
                'year_level' => '1st Year',
                'semester_level' => '1st Semester',
            ],
            [
                'status' => 1,
                'start_date' => '2026-06-01',
                'end_date' => '2026-10-15',
            ]
        );

        $it101 = Subject::updateOrCreate(
            ['subject_code' => 'IT101'],
            [
                'semester_id' => $bsitSem1->id,
                'des_title' => 'Introduction to Computing',
                'total_units' => 3,
                'lec_units' => 2,
                'lab_units' => 1,
                'total_hrs' => 5,
                'total_lec_hrs' => 2,
                'total_lab_hrs' => 3,
                'pre_requisite' => null,
            ]
        );

        $it102 = Subject::updateOrCreate(
            ['subject_code' => 'IT102'],
            [
                'semester_id' => $bsitSem1->id,
                'des_title' => 'Computer Programming 1',
                'total_units' => 3,
                'lec_units' => 2,
                'lab_units' => 1,
                'total_hrs' => 5,
                'total_lec_hrs' => 2,
                'total_lab_hrs' => 3,
                'pre_requisite' => null,
            ]
        );

        $cs101 = Subject::updateOrCreate(
            ['subject_code' => 'CS101'],
            [
                'semester_id' => $bscsSem1->id,
                'des_title' => 'Discrete Structures',
                'total_units' => 3,
                'lec_units' => 3,
                'lab_units' => 0,
                'total_hrs' => 3,
                'total_lec_hrs' => 3,
                'total_lab_hrs' => 0,
                'pre_requisite' => null,
            ]
        );

        $room101 = Room::updateOrCreate(
            ['roomNumber' => 'Room 101'],
            [
                'type' => 'Lecture',
                'capacity' => 45,
                'status' => 0,
            ]
        );

        $lab201 = Room::updateOrCreate(
            ['roomNumber' => 'Lab 201'],
            [
                'type' => 'Laboratory',
                'capacity' => 35,
                'status' => 0,
            ]
        );

        $facultyUser = User::where('email', 'faculty@example.com')->first();

        if (! $facultyUser) {
            return;
        }

        $faculty = Faculty::updateOrCreate(
            ['user_id' => $facultyUser->id],
            [
                'designation' => 'Instructor I',
                'department' => 'CCS',
                'profile_picture' => null,
                'deload_units' => 0,
                't_load_units' => 21,
                'overload_units' => 0,
                'status' => 0,
            ]
        );

        Expertise::updateOrCreate(
            [
                'faculty_id' => $faculty->id,
                'list_of_expertise' => 'Programming Fundamentals',
            ],
            []
        );

        Expertise::updateOrCreate(
            [
                'faculty_id' => $faculty->id,
                'list_of_expertise' => 'Database Systems',
            ],
            []
        );

        $defaultFacultyAvailabilityDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        foreach ($defaultFacultyAvailabilityDays as $day) {
            FacultyAvailability::updateOrCreate(
                [
                    'faculty_id' => $faculty->id,
                    'day_of_week' => $day,
                    'start_time' => '07:00:00',
                    'end_time' => '21:00:00',
                ],
                []
            );
        }

        $defaultRoomAvailabilityDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        foreach ([$room101, $lab201] as $room) {
            foreach ($defaultRoomAvailabilityDays as $day) {
                RoomAvailability::updateOrCreate(
                    [
                        'room_id' => $room->id,
                        'day' => $day,
                        'start_time' => '07:00:00',
                        'end_time' => '21:00:00',
                    ],
                    []
                );
            }
        }

        $loading1 = FacultyLoading::updateOrCreate(
            [
                'faculty_id' => $faculty->id,
                'subject_id' => $it101->id,
                'room_id' => $room101->id,
                'type' => 'Lecture',
                'day' => 'Monday',
                'start_time' => '09:00:00',
                'end_time' => '11:00:00',
            ],
            []
        );

        $loading2 = FacultyLoading::updateOrCreate(
            [
                'faculty_id' => $faculty->id,
                'subject_id' => $it102->id,
                'room_id' => $lab201->id,
                'type' => 'Laboratory',
                'day' => 'Wednesday',
                'start_time' => '14:00:00',
                'end_time' => '17:00:00',
            ],
            []
        );

        CreateSchedule::updateOrCreate(
            ['faculty_loading_id' => $loading1->id],
            [
                'year_level' => 1,
                'section' => 'A',
            ]
        );

        CreateSchedule::updateOrCreate(
            ['faculty_loading_id' => $loading2->id],
            [
                'year_level' => 1,
                'section' => 'B',
            ]
        );

        // Keep CS subject connected in data graph.
        FacultyLoading::updateOrCreate(
            [
                'faculty_id' => $faculty->id,
                'subject_id' => $cs101->id,
                'room_id' => $room101->id,
                'type' => 'Lecture',
                'day' => 'Friday',
                'start_time' => '10:00:00',
                'end_time' => '12:00:00',
            ],
            []
        );
    }
}


