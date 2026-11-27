<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimetableEntryResource;
use App\Models\Section;
use App\Models\TimetableEntry;
use App\Services\TimetableImport\ImportIssue;
use App\Services\TimetableImport\SheetReader;
use App\Services\TimetableImport\TimetableLoader;
use App\Services\TimetableImport\TimetableParser;
use App\Services\TimetableImport\TimetableRow;
use App\Support\DayTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The timetable (backend/app/routers/timetable.py):
 *   GET  /api/timetable         list entries; the personal timetable calls it with teacher_id or section_id
 *   POST /api/timetable/import  upload a department timetable. Python reads .xlsx and .csv;
 *                               PHP also reads .xls and .pdf (see PdfTableReader).
 */
class TimetableController extends Controller
{
    private const MAX_IMPORT_MB = 10;

    private const PREVIEW_ROWS = 25;

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

    /**
     * Upload a department timetable (.xlsx, .xls, .csv or .pdf). HOD/admin.
     * dry_run=true (the default) only previews: nothing is saved. With
     * dry_run=false the classes are split into sections, courses, teachers and
     * rooms and saved in one transaction (all or nothing).
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'department_code' => ['required', 'string'],
            'dry_run' => ['sometimes', 'nullable'],
            'file' => ['required', 'file'],
        ]);
        $dryRun = filter_var($request->input('dry_run', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($dryRun === null) {
            throw ValidationException::withMessages(['dry_run' => 'Input should be a valid boolean']);
        }

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        if (! in_array(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), SheetReader::EXTENSIONS, true)) {
            abort(400, 'Upload an .xlsx, .csv or .pdf file');
        }
        $code = strtoupper(trim($request->input('department_code')));
        if (! in_array($code, TimetableParser::DEPARTMENTS, true)) {
            abort(422, 'department_code must be one of '.json_encode(TimetableParser::DEPARTMENTS));
        }
        if ($file->getSize() > self::MAX_IMPORT_MB * 1024 * 1024) {
            abort(413, 'File too large (max '.self::MAX_IMPORT_MB.' MB)');
        }
        if ($file->getSize() === 0) {
            abort(400, 'Uploaded file is empty');
        }

        try {
            $sheets = SheetReader::read($file->getRealPath(), $originalName);
        } catch (Throwable $e) {   // a corrupt workbook, an encrypted or scanned PDF, ...
            abort(400, 'Could not read the file: '.$e->getMessage());
        }

        $parsed = TimetableParser::parse($sheets, $code);
        [$good, $validationIssues] = TimetableParser::validate($parsed->rows);
        $issues = [...$parsed->issues, ...$validationIssues];

        $report = [
            'dry_run' => $dryRun,
            'sheets_read' => $parsed->sheetsRead,
            'sheets_skipped' => $parsed->sheetsSkipped,
            'rows_parsed' => count($parsed->rows),
            'rows_valid' => count($good),
            'issues' => array_map(fn (ImportIssue $i) => $i->toArray(), $issues),
            'preview' => array_map(fn (TimetableRow $r) => [
                'section' => $r->section, 'day' => $r->day,
                'start_time' => substr($r->startTime, 0, 5), 'end_time' => substr($r->endTime, 0, 5),
                'course_code' => $r->courseCode, 'course_title' => $r->courseTitle,
                'teacher' => $r->teacher, 'room' => $r->room,
            ], array_slice($good, 0, self::PREVIEW_ROWS)),
            'entries_created' => 0,
            'entries_skipped_existing' => 0,
            'sections_created' => 0,
            'courses_created' => 0,
            'teachers_created' => 0,
            'rooms_created' => [],
        ];
        if ($dryRun || $good === []) {
            return response()->json($report);
        }

        try {
            $summary = DB::transaction(fn () => TimetableLoader::load($good, $code));
        } catch (Throwable $e) {
            report($e);
            abort(500, 'Import failed and was rolled back: '.$e->getMessage());
        }

        return response()->json([
            ...$report,
            'entries_created' => $summary->entriesCreated,
            'entries_skipped_existing' => $summary->entriesSkippedExisting,
            'sections_created' => $summary->sectionsCreated,
            'courses_created' => $summary->coursesCreated,
            'teachers_created' => $summary->teachersCreated,
            'rooms_created' => $summary->roomsCreated,
        ]);
    }
}
