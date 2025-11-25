<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueRoomAvailability implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */

    public function __construct($roomId)
    {
        $this->roomId = $roomId;
    }
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Ang $attribute kay murag 'availabilities.0', 'availabilities.1', etc.
        // Kuhaon nato ang index para makuha ang sakto nga start_time ug end_time
        $index = explode('.', $attribute)[1];
        
        $day = $value; // Ang $value mao ang 'day'
        $startTime = request()->input("availabilities.{$index}.start_time");
        $endTime = request()->input("availabilities.{$index}.end_time");

        // I-check sa database kung naa na bay existing record nga parehas
        $exists = DB::table('room_availabilities')
            ->where('room_id', $this->roomId)
            ->where('day', $day)
            ->where('start_time', $startTime)
            ->where('end_time', $endTime)
            ->exists();
        
        if ($exists) {
            // Kung naa, i-fail ang validation ug ihatag kini nga message
            $fail("The availability slot for {$day} from {$startTime} to {$endTime} already exists for this room.");
        }
    }
}
