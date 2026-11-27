<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Resources\CourseResource;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\RoomResource;
use App\Models\Course;
use App\Models\Department;
use App\Models\Room;
use App\Models\TimetableEntry;
use App\Support\DayTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Rooms and reference data (backend/app/routers/rooms.py). Every endpoint needs
 * a login; creating and changing rooms is for HODs and admins.
 */
class RoomController extends Controller
{
    private const DUPLICATE_NAME = 'A room with this name already exists';

    /** Rooms by name. Filters: min_capacity, building, search (part of the name). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $request->validate([
            'min_capacity' => ['nullable', 'integer', 'min:1'],
            'building' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'skip' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $rooms = Room::query()->orderBy('name')
            ->when($q['min_capacity'] ?? null, fn (Builder $b, $min) => $b->where('capacity', '>=', $min))
            ->when($q['building'] ?? null, fn (Builder $b, $building) => $b->where('building', $building))
            ->when($q['search'] ?? null, fn (Builder $b, $search) => $b->where('name', 'like', "%$search%"))
            ->skip((int) ($q['skip'] ?? 0))->take((int) ($q['limit'] ?? 100))
            ->get();

        return RoomResource::collection($rooms);
    }

    /** Rooms with no timetable entry overlapping [start, end) on that day; unknown capacity last. */
    public function empty(Request $request): AnonymousResourceCollection
    {
        $q = $request->validate([
            'day' => ['required', 'string'],
            'start' => ['required', 'string'],
            'end' => ['required', 'string'],
            'min_capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $day = DayTime::normalizeDay($q['day']);
        $start = DayTime::parseTime($q['start']);
        $end = DayTime::parseTime($q['end']);
        if ($day === null) {
            abort(422, 'Unrecognised day: '.DayTime::repr($q['day']));
        }
        if ($start === null || $end === null) {
            abort(422, 'start and end must look like HH:MM');
        }
        if ($end <= $start) {
            abort(422, 'end must be after start');
        }

        $rooms = Room::query()
            ->whereNotExists(fn ($busy) => $busy->from('timetable_entries')
                ->whereColumn('timetable_entries.room_id', 'rooms.id')
                ->where('timetable_entries.day_of_week', $day)
                ->where('timetable_entries.start_time', '<', $end)    // it starts before we end
                ->where('timetable_entries.end_time', '>', $start))   // and ends after we start
            ->when($q['min_capacity'] ?? null, fn (Builder $b, $min) => $b->where('capacity', '>=', $min))
            // SQL sorts NULL first; a room of unknown size shouldn't top the list.
            ->orderByRaw('capacity IS NULL')->orderBy('capacity')->orderBy('name')
            ->get();

        return RoomResource::collection($rooms);
    }

    public function show(int $roomId): RoomResource
    {
        return new RoomResource($this->find($roomId));
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (Room::where('name', $data['name'])->exists()) {
            abort(409, self::DUPLICATE_NAME);
        }

        try {
            $room = Room::create($data);
        } catch (UniqueConstraintViolationException) {   // the same name sent twice at once
            abort(409, self::DUPLICATE_NAME);
        }

        return (new RoomResource($room))->response()->setStatusCode(201);
    }

    /** Changes only the fields sent. */
    public function update(UpdateRoomRequest $request, int $roomId): RoomResource
    {
        $room = $this->find($roomId);
        $data = $request->validated();

        if (array_key_exists('name', $data) && $data['name'] !== $room->name
            && Room::where('name', $data['name'])->exists()) {
            abort(409, self::DUPLICATE_NAME);
        }

        try {
            $room->update($data);
        } catch (UniqueConstraintViolationException) {
            abort(409, self::DUPLICATE_NAME);
        }

        return new RoomResource($room->fresh());
    }

    public function departments(): AnonymousResourceCollection
    {
        return DepartmentResource::collection(Department::orderBy('name')->get());
    }

    /** Courses by code. Filters: department_id, search (code or title). */
    public function courses(Request $request): AnonymousResourceCollection
    {
        $q = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string'],
            'skip' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $courses = Course::query()->orderBy('code')
            ->when($q['department_id'] ?? null, fn (Builder $b, $id) => $b->where('department_id', $id))
            ->when($q['search'] ?? null, fn (Builder $b, $search) => $b->where(
                fn (Builder $w) => $w->where('code', 'like', "%$search%")->orWhere('title', 'like', "%$search%")
            ))
            ->skip((int) ($q['skip'] ?? 0))->take((int) ($q['limit'] ?? 100))
            ->get();

        return CourseResource::collection($courses);
    }

    private function find(int $roomId): Room
    {
        return Room::find($roomId) ?? abort(404, 'Room not found');
    }
}
