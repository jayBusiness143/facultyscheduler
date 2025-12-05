<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Room; 
use App\Models\RoomAvailability;
use Illuminate\Support\Facades\Validator; 
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Rules\UniqueRoomAvailability;

class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            // Kuhaon ang tanan nga rooms ug i-sort base sa roomNumber, ascending
            $rooms = Room::orderBy('roomNumber', 'asc')->get();

            // I-return ang lista sa rooms isip JSON
            return response()->json([
                'rooms' => $rooms
            ], 200); // 200 OK

        } catch (\Exception $e) {
            // Kung naay error, i-return ang 500 status
            return response()->json([
                'message' => 'An error occurred while fetching rooms.',
                'error' => $e->getMessage()
            ], 500); // 500 Internal Server Error
        }
    }

    public function getRoomAvailability(Room $room)
    {
        try {
            // Kuhaon ang tanang availabilities nga para lang sa gi-provide nga room
            // ug i-sort kini base sa adlaw ug oras
            $availabilities = $room->availabilities()
                                   ->orderByRaw("FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")
                                   ->orderBy('start_time', 'asc')
                                   ->get();

            return response()->json([
                'availabilities' => $availabilities
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve availability for the room.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get availabilities for all rooms (sorted by roomNumber, then day and start_time)
     */
    public function getAllRoomsAvailability()
{
        try {
            // Kuhaon ang tanan nga rooms ug i-sort base sa roomNumber, ascending
            $rooms = Room::with('availabilities')
            ->where('status', 0)
            ->get();

            // I-return ang lista sa rooms isip JSON
            return response()->json([
                'rooms' => $rooms
            ], 200); // 200 OK

        } catch (\Exception $e) {
            // Kung naay error, i-return ang 500 status
            return response()->json([
                'message' => 'An error occurred while fetching rooms.',
                'error' => $e->getMessage()
            ], 500); // 500 Internal Server Error
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // 1. Validation para sa Room ra
        $validator = Validator::make($request->all(), [
            'roomNumber' => 'required|string|max:255|unique:rooms,roomNumber',
            'type' => 'required|string|in:Lecture,Laboratory',
            'capacity' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // 2. I-save lang ang Room sa database
            $room = Room::create($validator->validated());

            // 3. I-return ang success message ug ang bag-ong room
            return response()->json([
                'message' => 'Room created successfully!',
                'room' => $room
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while creating the room.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function storeRoomAvailability(Request $request, Room $room)
    {
        // 1. Validation para sa array sa availabilities
        $validator = Validator::make($request->all(), [
            'availabilities' => 'required|array|min:1',
            'availabilities.*.day' => [
                'required',
                'string',
                Rule::in(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']),
                // --- GAMITON ANG BAG-ONG CUSTOM RULE ---
                // Ipasa ang ID sa room para mahibaw-an sa rule kung asa nga room i-check
                new UniqueRoomAvailability($room->id),
            ],
            'availabilities.*.start_time' => 'required|date_format:H:i:s',
            'availabilities.*.end_time' => 'required|date_format:H:i:s|after:availabilities.*.start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $createdAvailabilities = [];

            // 2. I-loop ug i-save kini sa database
            foreach ($request->availabilities as $availability) {
                // Dili na kinahanglan mag-check dinhi kay na-handle na sa validation
                $newAvailability = $room->availabilities()->create([
                    'day' => $availability['day'],
                    'start_time' => $availability['start_time'],
                    'end_time' => $availability['end_time'],
                ]);
                $createdAvailabilities[] = $newAvailability;
            }

            // 3. I-return ang success message
            return response()->json([
                'message' => 'Availability slots added successfully to the room!',
                'availabilities' => $createdAvailabilities
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while adding availability.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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
    public function update(Request $request, Room $room)
    {
        // 1. Validation para sa pag-update
        $validator = Validator::make($request->all(), [
            'roomNumber' => [
                'required',
                'string',
                'max:255',
                // Siguraduhon nga ang roomNumber kay unique, pero i-ignore ang kasamtangang room
                Rule::unique('rooms')->ignore($room->id),
            ],
            'type' => 'required|string|in:Lecture,Laboratory',
            'capacity' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // 2. I-update ang room gamit ang validated data
            $room->update($validator->validated());

            // 3. I-return ang success message ug ang gi-update nga room data
            return response()->json([
                'message' => 'Room updated successfully!',
                'room' => $room
            ], 200); // 200 OK

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while updating the room.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function destroyRoomAvailability(RoomAvailability $availability)
    {
        try {
            // I-delete ang specific availability slot
            $availability->delete();

            // I-return ang success message
            return response()->json([
                'message' => 'Availability slot deleted successfully!'
            ], 200); // Pwede pud 204 No Content

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while deleting the slot.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
