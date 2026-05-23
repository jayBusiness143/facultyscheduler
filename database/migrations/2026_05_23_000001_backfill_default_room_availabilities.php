<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $now = now();

        $rooms = DB::table('rooms')->select('id')->get();

        foreach ($rooms as $room) {
            foreach ($days as $day) {
                $exists = DB::table('room_availabilities')
                    ->where('room_id', $room->id)
                    ->where('day', $day)
                    ->where('start_time', '07:00:00')
                    ->where('end_time', '21:00:00')
                    ->exists();

                if (! $exists) {
                    DB::table('room_availabilities')->insert([
                        'room_id' => $room->id,
                        'day' => $day,
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
        DB::table('room_availabilities')
            ->whereIn('day', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
            ->where('start_time', '07:00:00')
            ->where('end_time', '21:00:00')
            ->delete();
    }
};
