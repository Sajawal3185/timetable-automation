<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimetableEntryResource;
use App\Models\Section;
use App\Models\TimetableEntry;
use App\Support\DayTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/timetable (backend/app/routers/timetable.py). The personal timetable
 * screen calls it with teacher_id or section_id.
 *
 * Not ported yet: POST /api/timetable/import (the spreadsheet importer, about
 * 500 lines of timetable_import.py). The PHP database gets its rows by copying
 * FastAPI's database, so the demo doesn't need it.
 */
class TimetableController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $request->validate([
            'section_id' => ['nullable', 'integer'],
            'teacher_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'course_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'day' => ['nullable', 'string'],
            'skip' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:2000'],
        ]);

        $query = TimetableEntry::query();
        foreach (['section_id', 'teacher_id', 'room_id', 'course_id'] as $column) {
            if ($q[$column] ?? null) {   // 0 means "no filter", as in Python
                $query->where($column, $q[$column]);
            }
        }
        if ($q['department_id'] ?? null) {
            $query->whereIn('section_id', Section::select('id')->where('department_id', $q['department_id']));
        }
        if ($q['day'] ?? null) {
            $day = DayTime::normalizeDay($q['day']) ?? abort(422, 'Unrecognised day: '.DayTime::repr($q['day']));
            $query->where('day_of_week', $day);
        }

        // Day NAME order (Friday, Monday, ...), exactly like Python; the frontend sorts by weekday itself.
        $entries = $query->orderBy('day_of_week')->orderBy('start_time')
            ->skip((int) ($q['skip'] ?? 0))->take((int) ($q['limit'] ?? 500))
            ->get();

        return TimetableEntryResource::collection($entries);
    }
}
