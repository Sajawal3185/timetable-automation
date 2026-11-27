<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookMakeupRequest;
use App\Http\Requests\FindMakeupSlotsRequest;
use App\Http\Resources\MakeupClassResource;
use App\Models\Course;
use App\Models\MakeupClass;
use App\Models\Room;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\User;
use App\Services\MakeupSolver;
use App\Services\Notifier;
use App\Services\SchedulerRules;
use App\Support\DayTime;
use App\Support\Fmt;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makeup classes (backend/app/routers/makeup.py): find free slots, book one, list them.
 * Staff may search and book: a teacher for themselves, a HOD for their
 * department's teachers, an admin for anyone.
 */
class MakeupController extends Controller
{
    public function findSlots(FindMakeupSlotsRequest $request, MakeupSolver $solver): JsonResponse
    {
        $q = $request->validated();
        $days = ($q['days'] ?? null) ?: MakeupSolver::WEEKDAYS;
        foreach ($days as $day) {
            self::refuseBadDay($day);
        }
        $teacher = Teacher::find($q['teacher_id']);
        if ($teacher !== null) {   // a missing teacher is reported by the solver (422)
            self::checkBooksFor($request->user(), $teacher);
        }

        try {
            $slots = $solver->find((int) $q['section_id'], (int) $q['teacher_id'], (int) $q['course_id'],
                (int) ($q['duration_minutes'] ?? 60), isset($q['preferred_room_id']) ? (int) $q['preferred_room_id'] : null,
                $days, $q['term'] ?? 'Fall 2026');
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($slots);
    }

    public function store(BookMakeupRequest $request, MakeupSolver $solver): JsonResponse
    {
        $b = $request->validated();
        $start = Fmt::time($b['start_time']);
        $end = Fmt::time($b['end_time']);
        if ($end <= $start) {
            abort(422, 'end_time must be after start_time');
        }
        self::refuseBadDay($b['day_of_week']);

        $section = Section::find($b['section_id']);
        $teacher = Teacher::find($b['teacher_id']);
        $course = Course::find($b['course_id']);
        $room = Room::find($b['room_id']);
        if (! $section || ! $teacher || ! $course || ! $room) {
            abort(404, 'Section, teacher, course, or room not found');
        }
        self::checkBooksFor($request->user(), $teacher);

        if ($unsuitable = SchedulerRules::roomSuitability($section->student_count, $room->capacity, $course->is_lab, $room->room_type)) {
            abort(409, $unsuitable[0]['message']);
        }

        try {
            $makeup = DB::transaction(function () use ($b, $start, $end, $course, $room, $solver, $request) {
                // Lock room, teacher and section (always in that order: no deadlocks), so two
                // bookings for any of them run one after the other and the second sees the first.
                foreach ([Room::class => $b['room_id'], Teacher::class => $b['teacher_id'], Section::class => $b['section_id']] as $model => $id) {
                    $model::whereKey($id)->lockForUpdate()->first();
                }

                $candidate = ['day' => $b['day_of_week'], 'start' => $start, 'end' => $end, 'section_id' => (int) $b['section_id'],
                    'course_id' => (int) $b['course_id'], 'teacher_id' => (int) $b['teacher_id'], 'room_id' => (int) $b['room_id']];
                if ($clashes = SchedulerRules::hardClashes($candidate, $solver->activeSlots($b['term'] ?? 'Fall 2026'))) {
                    abort(409, $clashes[0]['message']);
                }

                $makeup = MakeupClass::create([
                    'course_id' => $b['course_id'], 'section_id' => $b['section_id'], 'teacher_id' => $b['teacher_id'],
                    'room_id' => $b['room_id'], 'class_date' => $b['class_date'] ?? null, 'day_of_week' => $b['day_of_week'],
                    'start_time' => $start, 'end_time' => $end, 'reason' => $b['reason'] ?? null,
                    'status' => 'scheduled', 'created_by' => $request->user()->id,
                ]);

                // The teacher and the section's students hear about it, in the same transaction:
                // a refused booking leaves no notification behind.
                $when = "{$b['day_of_week']} ".substr($start, 0, 5).'-'.substr($end, 0, 5);
                if (! empty($b['class_date'])) {
                    $when = CarbonImmutable::parse($b['class_date'])->format('d M Y').", $when";
                }
                Notifier::notifyMany(Notifier::recipientsForMakeup((int) $b['teacher_id'], (int) $b['section_id']),
                    'makeup_added', "Makeup class added: {$course->code} on $when in {$room->name}.", $makeup->id);

                return $makeup;
            });
        } catch (UniqueConstraintViolationException) {
            // uq_makeup_room_slot: someone booked this room at this time a moment ago.
            abort(409, 'This room was just booked for that time. Search again for a free slot.');
        }

        return (new MakeupClassResource($makeup->fresh()))->response()->setStatusCode(201);
    }

    /** Scheduled makeups by day and time. Filters: section_id, teacher_id. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $request->validate(['section_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer']]);

        $makeups = MakeupClass::where('status', 'scheduled')
            ->when($q['section_id'] ?? null, fn ($w, $id) => $w->where('section_id', $id))
            ->when($q['teacher_id'] ?? null, fn ($w, $id) => $w->where('teacher_id', $id))
            ->orderBy('day_of_week')->orderBy('start_time')->get();

        return MakeupClassResource::collection($makeups);
    }

    /** 403 unless the user may act for this teacher: themselves, a HOD's own department, or an admin. */
    private static function checkBooksFor(User $user, Teacher $teacher): void
    {
        if ($user->role === Roles::ADMIN || $teacher->user_id === $user->id) {
            return;
        }
        if ($user->role === Roles::HOD && $user->department_id !== null && $user->department_id === $teacher->department_id) {
            return;
        }
        abort(403, 'You can only book makeup classes for yourself');
    }

    private static function refuseBadDay(string $day): void
    {
        if (! in_array($day, DayTime::DAYS, true)) {   // full names only, like Python's VALID_DAYS
            abort(422, 'Invalid day_of_week: '.DayTime::repr($day));
        }
    }
}
