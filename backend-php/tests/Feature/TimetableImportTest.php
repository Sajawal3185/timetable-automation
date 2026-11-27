<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\Room;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Services\TimetableImport\TimetableLoader;
use App\Services\TimetableImport\TimetableRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\TinyPdf;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * POST /api/timetable/import and the loader: ports of test_timetable_api.py
 * (import tests) and the load_rows tests of test_timetable_import.py, plus PDF uploads.
 */
class TimetableImportTest extends TestCase
{
    private Department $dept;

    private Course $c1;

    private Room $mid;

    /** conftest.py's seed: one department, two courses, three rooms. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        $this->c1 = Course::factory()->create(['code' => 'CS101', 'title' => 'Programming Fundamentals', 'department_id' => $this->dept->id]);
        Course::factory()->create(['code' => 'SE201', 'title' => 'Software Design', 'department_id' => $this->dept->id]);
        Room::factory()->create(['name' => 'R-1', 'capacity' => 30]);
        $this->mid = Room::factory()->create(['name' => 'R-2', 'capacity' => 60]);
        Room::factory()->create(['name' => 'LT-1', 'capacity' => 150]);
    }

    // ------------------------------------------------------------ the endpoint

    public function test_import_dry_run_saves_nothing(): void
    {
        $this->asRole('admin');

        $body = $this->upload(['department_code' => 'se'], 'se.xlsx', Workbooks::apiFlat())->assertOk()->json();

        $this->assertTrue($body['dry_run']);
        $this->assertSame([2, 2], [$body['rows_parsed'], $body['rows_valid']]);
        $this->assertCount(1, $body['issues']);
        $this->assertSame(4, $body['issues'][0]['row']);
        $this->assertSame('08:30', $body['preview'][0]['start_time']);
        $this->assertSame(['section' => 'BSSE-4A', 'day' => 'Monday', 'start_time' => '08:30', 'end_time' => '10:00',
            'course_code' => 'SE201', 'course_title' => 'Software Design', 'teacher' => 'Dr. Ali', 'room' => 'R-2'], $body['preview'][0]);
        $this->assertSame(0, $body['entries_created']);
        $this->assertSame(0, TimetableEntry::count());
    }

    public function test_import_for_real_then_again(): void
    {
        $this->asRole('hod');
        $send = fn () => $this->upload(['department_code' => 'SE', 'dry_run' => 'false'], 'se.xlsx', Workbooks::apiFlat())->assertOk()->json();

        $first = $send();
        $this->assertSame(2, $first['entries_created']);
        $this->assertSame(['NEW-7'], $first['rooms_created']);
        $this->assertSame(2, TimetableEntry::count());

        $second = $send();
        $this->assertSame([0, 2], [$second['entries_created'], $second['entries_skipped_existing']]);
        $this->assertSame(2, TimetableEntry::count());
    }

    public function test_import_rejects_bad_requests(): void
    {
        $xlsx = Workbooks::apiFlat();

        $this->asRole('teacher');
        $this->upload(['department_code' => 'SE'], 'se.xlsx', $xlsx)->assertForbidden();

        $this->asRole('admin');
        $this->upload(['department_code' => 'ZZ'], 'se.xlsx', $xlsx)->assertStatus(422)
            ->assertExactJson(['detail' => 'department_code must be one of ["AI","CS","IT","SE"]']);
        $this->upload(['department_code' => 'SE'], 'x.docx', 'abc')->assertStatus(400)
            ->assertExactJson(['detail' => 'Upload an .xlsx, .csv or .pdf file']);
        $this->upload(['department_code' => 'SE'], 'x.xlsx', '')->assertStatus(400)
            ->assertExactJson(['detail' => 'Uploaded file is empty']);
        $corrupt = $this->upload(['department_code' => 'SE'], 'x.xlsx', 'not really a workbook')->assertStatus(400);
        $this->assertStringStartsWith('Could not read the file', $corrupt->json('detail'));
        $this->upload(['department_code' => 'SE', 'dry_run' => 'maybe'], 'se.xlsx', $xlsx)->assertStatus(422);
        $this->post('/api/timetable/import', ['department_code' => 'SE'], ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['body', 'file']);
        $this->upload(['department_code' => 'SE'], 'big.csv', str_repeat('a', 10 * 1024 * 1024 + 1))->assertStatus(413);
    }

    public function test_list_timetable_after_an_import(): void
    {
        $this->asRole('admin');
        $this->upload(['department_code' => 'SE', 'dry_run' => 'false'], 'se.xlsx', Workbooks::apiFlat())->assertOk();

        $this->asRole('student');
        $this->assertCount(2, $this->getJson('/api/timetable')->json());
        $this->assertCount(2, $this->getJson('/api/timetable?day=mon')->json());
        $this->assertCount(2, $this->getJson("/api/timetable?department_id={$this->dept->id}")->json());
    }

    public function test_rooms_created_by_import_have_unknown_capacity_until_fixed(): void
    {
        $this->asRole('hod');
        $this->upload(['department_code' => 'SE', 'dry_run' => 'false'], 'se.xlsx', Workbooks::apiFlat())->assertOk();

        $rooms = collect($this->getJson('/api/rooms')->json())->keyBy('name');
        $this->assertNull($rooms['NEW-7']['capacity']);     // unknown, not a placeholder
        $this->assertSame(60, $rooms['R-2']['capacity']);   // existing rooms keep their real capacity
        $sized = array_column($this->getJson('/api/rooms?min_capacity=1')->json(), 'name');
        $this->assertNotContains('NEW-7', $sized);
        $this->putJson("/api/rooms/{$rooms['NEW-7']['id']}", ['capacity' => 55])->assertOk()->assertJsonPath('capacity', 55);
    }

    public function test_import_a_pdf_timetable(): void
    {
        $pages = [
            TinyPdf::gridPage('Section: BSSE-4A', ['08:30 - 10:00', '10:00 - 11:30'], [
                'Monday' => [0 => 'SE201 Software Design|Dr. Ali|R-2', 1 => 'Lunch'],
                'Wednesday' => [1 => 'CS101 Programming Fundamentals|Ms. Sara|Lab 4'],
            ]),
            TinyPdf::gridPage('Section: BSSE-4B', ['08:30 - 10:00', '10:00 - 11:30'], [
                'Tuesday' => [0 => 'SE201 Software Design|Dr. Ali|R-2'],
            ]),
        ];
        $this->asRole('admin');

        $body = $this->upload(['department_code' => 'SE', 'dry_run' => 'false'], 'se-fall.pdf', TinyPdf::make($pages))->assertOk()->json();

        $this->assertSame([], $body['issues']);
        $this->assertSame(['BSSE-4A', 'BSSE-4B'], $body['sheets_read']);
        $this->assertSame([3, 3, 3], [$body['rows_parsed'], $body['rows_valid'], $body['entries_created']]);
        $this->assertSame([2, 0, 2], [$body['sections_created'], $body['courses_created'], $body['teachers_created']]);
        $this->assertSame(['Lab 4'], $body['rooms_created']);

        // The data is split into the timetable's own tables, linked by id.
        $entry = TimetableEntry::where('day_of_week', 'Wednesday')->sole();
        $this->assertSame(['BSSE-4A', 'CS101', 'Ms. Sara', 'Lab 4', '10:00:00', '11:30:00'], [
            Section::find($entry->section_id)->name, Course::find($entry->course_id)->code,
            Teacher::find($entry->teacher_id)->name, Room::find($entry->room_id)->name, $entry->start_time, $entry->end_time,
        ]);
        $this->assertSame(2, TimetableEntry::where('teacher_id', Teacher::where('name', 'Dr. Ali')->value('id'))->count());
    }

    public function test_a_scanned_pdf_is_refused_with_a_clear_message(): void
    {
        $this->asRole('admin');

        $detail = $this->upload(['department_code' => 'SE'], 'scan.pdf', TinyPdf::make([[]]))->assertStatus(400)->json('detail');
        $this->assertStringContainsString('scanned', $detail);
    }

    // ------------------------------------------------------------ the loader

    public function test_load_rows_creates_everything_then_is_idempotent(): void
    {
        Course::query()->delete();   // like conftest's `db` fixture: an empty database
        Room::query()->delete();
        Department::query()->delete();
        $rows = [
            $this->row(teacher: 'Dr. Ali', room: 'R-9'),
            $this->row(day: 'Tuesday', teacher: 'Dr. Ali', room: 'R-9', sourceRow: 3),
            $this->row(startTime: '11:00:00', endTime: '12:00:00', teacher: 'TBA', room: '', sourceRow: 4),
        ];

        $first = TimetableLoader::load($rows, 'SE');
        $this->assertSame(3, $first->entriesCreated);
        $this->assertSame([1, 1, 1, 1], [$first->departmentsCreated, $first->sectionsCreated, $first->coursesCreated, $first->teachersCreated]);
        $this->assertSame(['R-9'], $first->roomsCreated);
        $this->assertNull(Room::where('name', 'R-9')->sole()->capacity);   // unknown, not made up

        $last = TimetableEntry::orderBy('id')->get()[2];
        $this->assertNull($last->teacher_id);   // TBA -> no teacher
        $this->assertNull($last->room_id);      // blank -> no room

        $second = TimetableLoader::load($rows, 'SE');
        $this->assertSame([0, 3], [$second->entriesCreated, $second->entriesSkippedExisting]);
        $this->assertSame(3, TimetableEntry::count());
    }

    public function test_load_rows_reuses_existing_course_and_room(): void
    {
        $summary = TimetableLoader::load([
            $this->row(courseCode: 'cs101', courseTitle: 'Programming Fundamentals', room: 'r-2', section: 'BSSE-1A'),
        ], 'SE');

        $this->assertSame([0, [], 0, 1], [$summary->coursesCreated, $summary->roomsCreated, $summary->departmentsCreated, $summary->sectionsCreated]);
        $entry = TimetableEntry::sole();
        $this->assertSame([$this->c1->id, $this->mid->id], [$entry->course_id, $entry->room_id]);
    }

    // ------------------------------------------------------------ helpers

    private function upload(array $fields, string $name, string $bytes): TestResponse
    {
        return $this->post('/api/timetable/import', [...$fields, 'file' => UploadedFile::fake()->createWithContent($name, $bytes)],
            ['Accept' => 'application/json']);
    }

    private function row(...$changes): TimetableRow
    {
        return new TimetableRow(...[
            'department' => 'SE', 'section' => 'A', 'day' => 'Monday', 'startTime' => '09:00:00', 'endTime' => '10:00:00',
            'courseTitle' => 'Maths', 'courseCode' => 'M1', 'sourceSheet' => 'A', 'sourceRow' => 2, ...$changes,
        ]);
    }
}
