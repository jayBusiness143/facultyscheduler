<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $now = now();

        $faculties = DB::table('faculties')->select('id')->get();

        foreach ($faculties as $faculty) {
            foreach ($days as $day) {
                $exists = DB::table('faculty_availabilities')
                    ->where('faculty_id', $faculty->id)
                    ->where('day_of_week', $day)
                    ->where('start_time', '07:00:00')
                    ->where('end_time', '21:00:00')
                    ->exists();

                if (! $exists) {
                    DB::table('faculty_availabilities')->insert([
                        'faculty_id' => $faculty->id,
                        'day_of_week' => $day,
                        'start_time' => '07:00:00',
                        'end_time' => '21:00:00',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('faculty_availabilities')
            ->whereIn('day_of_week', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
            ->where('start_time', '07:00:00')
            ->where('end_time', '21:00:00')
            ->delete();
    }
};
