<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Department;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Institutional event calendar (backend/app/routers/events.py).
 * Reading needs a login; create, change and delete are for admins and HODs
 * (the route middleware does that, as for rooms).
 */
class EventController extends Controller
{
    private const DB_CONSTRAINT = 'Database constraint violation (e.g., end_date cannot be earlier than start_date)';

    /**
     * Filters: month=YYYY-MM, from_date, to_date, event_type, department_id.
     * An event that overlaps the range is included. department_id also keeps the
     * university-wide events (no department).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $request->validate([
            'month' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'event_type' => ['nullable', Rule::in(Event::TYPES)],
            'department_id' => ['nullable', 'integer'],
            'skip' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $from = $q['from_date'] ?? null;
        $to = $q['to_date'] ?? null;
        if (($q['month'] ?? '') !== '') {
            [$from, $to] = $this->monthRange($q['month']);   // month wins over from/to
        }
        if ($from && $to && $to < $from) {   // ISO dates compare correctly as text
            abort(422, 'to_date cannot be before from_date');
        }

        $events = Event::query()->orderBy('start_date')->orderBy('id')
            ->when($to, fn (Builder $b, $d) => $b->where('start_date', '<=', $d))
            ->when($from, fn (Builder $b, $d) => $b->whereRaw('COALESCE(end_date, start_date) >= ?', [$d]))
            ->when($q['event_type'] ?? null, fn (Builder $b, $type) => $b->where('event_type', $type))
            ->when($q['department_id'] ?? null, fn (Builder $b, $id) => $b->where(
                fn (Builder $w) => $w->where('department_id', $id)->orWhereNull('department_id')
            ))
            ->skip((int) ($q['skip'] ?? 0))->take((int) ($q['limit'] ?? 200))
            ->get();

        return EventResource::collection($events);
    }

    public function show(int $eventId): EventResource
    {
        return new EventResource($this->find($eventId));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'event_type' => ['required', Rule::in(Event::TYPES)],
            'description' => ['nullable', 'string'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'location' => ['nullable', 'string', 'max:150'],
            'department_id' => ['nullable', 'integer'],
        ]);

        $this->checkDepartment($data['department_id'] ?? null);

        try {
            $event = Event::create([...$data, 'created_by' => $request->user()->id]);
        } catch (QueryException $e) {
            $this->integrityTo422($e);
        }

        return (new EventResource($event->fresh()))->response()->setStatusCode(201);
    }

    /** PUT with a partial body: only the fields sent are changed. */
    public function update(Request $request, int $eventId): EventResource
    {
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'min:1', 'max:200'],
            'event_type' => ['sometimes', 'nullable', Rule::in(Event::TYPES)],
            'description' => ['sometimes', 'nullable', 'string'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $event = $this->find($eventId);

        // An explicit null would break these columns; say so instead of a 500.
        foreach (['title', 'event_type', 'start_date'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                abort(422, "$field cannot be null");
            }
        }

        $this->checkDepartment($data['department_id'] ?? null);

        $event->fill($data);   // not saved yet, so a refused change leaves the row alone
        if ($event->end_date && $event->end_date->lt($event->start_date)) {
            abort(422, 'end_date cannot be before start_date');
        }

        try {
            $event->save();
        } catch (QueryException $e) {
            $this->integrityTo422($e);
        }

        return new EventResource($event->fresh());
    }

    public function destroy(int $eventId): Response
    {
        $this->find($eventId)->delete();

        return response()->noContent();
    }

    private function find(int $eventId): Event
    {
        return Event::find($eventId) ?? abort(404, 'Event not found');
    }

    private function checkDepartment(?int $departmentId): void
    {
        if ($departmentId !== null && Department::find($departmentId) === null) {
            abort(404, 'Department not found');
        }
    }

    /** Python catches IntegrityError only: anything else stays a real error. */
    private function integrityTo422(QueryException $e): never
    {
        if ((string) $e->getCode() === '23000') {
            abort(422, self::DB_CONSTRAINT);
        }

        throw $e;
    }

    /** "2026-10" -> ["2026-10-01", "2026-10-31"]; anything else is a 422. */
    private function monthRange(string $month): array
    {
        $parts = explode('-', $month);
        if (count($parts) === 2 && preg_match('/^\s*\d+\s*$/', $parts[0]) && preg_match('/^\s*\d+\s*$/', $parts[1])) {
            [$year, $mon] = [(int) $parts[0], (int) $parts[1]];
            if ($year >= 1 && $year <= 9999 && checkdate($mon, 1, $year)) {
                $first = Carbon::createFromDate($year, $mon, 1);

                return [$first->toDateString(), $first->endOfMonth()->toDateString()];
            }
        }

        abort(422, 'month must look like 2026-10');
    }
}
