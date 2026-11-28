<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Files;
use Tests\Support\SeedsArchive;
use Tests\TestCase;

/** Port of backend/tests/test_exams_api.py: upload, search, download, delete, file safety. */
class ExamPapersApiTest extends TestCase
{
    use SeedsArchive;

    private const JSON = ['Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedArchive();
    }

    /** upload_paper(): a null value leaves that form field out. */
    private function upload(string $who, array $over = [], ?UploadedFile $file = null): TestResponse
    {
        $this->actAs($who);
        $data = array_filter([
            'course_id' => $this->c1->id, 'title' => 'CS101 Midterm', 'term' => 'Fall 2025',
            'exam_type' => 'midterm', 'year' => 2025, 'instructor_name' => 'Dr. Ali', ...$over,
        ], fn ($value) => $value !== null);

        return $this->post('/api/exam-papers', [...$data, 'file' => $file ?? Files::upload('paper.pdf', Files::pdf())], self::JSON);
    }

    /** The smallest valid form, with the file of your choice. */
    private function bare(string $who, UploadedFile $file): TestResponse
    {
        $this->actAs($who);

        return $this->post('/api/exam-papers', [
            'course_id' => $this->c1->id, 'title' => 't', 'term' => 'Fall 2025', 'exam_type' => 'final', 'file' => $file,
        ], self::JSON);
    }

    private function search(array $params = []): array
    {
        $this->actAs('student');

        return $this->getJson('/api/exam-papers?'.http_build_query($params))->assertOk()->json();
    }

    public function test_exam_upload_permissions_and_validation(): void
    {
        $this->upload('student')->assertForbidden();
        $this->upload('teacher', ['course_id' => 999])->assertNotFound()->assertExactJson(['detail' => 'Course not found']);
        $this->upload('teacher', ['exam_type' => 'bogus'])->assertStatus(422);

        $this->bare('teacher', Files::upload('evil.exe', Files::pdf()))->assertStatus(400)
            ->assertExactJson(['detail' => "File type .exe not allowed. Allowed: ['.doc', '.docx', '.jpeg', '.jpg', '.pdf', '.png']"]);
        $this->bare('teacher', Files::upload('paper.pdf', ''))->assertStatus(400)
            ->assertExactJson(['detail' => 'Uploaded file is empty']);
    }

    public function test_exam_upload_download_roundtrip(): void
    {
        $response = $this->upload('teacher')->assertCreated();
        $paper = $response->json();

        $this->assertSame('paper.pdf', $paper['original_filename']);
        $this->assertSame(strlen(Files::pdf()), $paper['file_size']);
        $this->assertArrayNotHasKey('file_path', $paper);   // server paths are never exposed

        $this->actAs('student');
        $download = $this->get("/api/exam-papers/{$paper['id']}/download")->assertOk();
        $this->assertSame(Files::pdf(), $download->streamedContent());

        $stored = Storage::disk('local')->allFiles('exam_papers');
        $this->assertCount(1, $stored);
        $this->assertNotSame('paper.pdf', basename($stored[0]));   // random name on disk
    }

    public function test_hostile_filename_cannot_escape_upload_folder(): void
    {
        $response = $this->bare('teacher', Files::upload('../../etc/passwd.pdf', Files::pdf()))->assertCreated();

        $this->assertSame('passwd.pdf', $response->json('original_filename'));
        foreach (Storage::disk('local')->allFiles() as $path) {
            $this->assertStringStartsWith('exam_papers/', $path);
        }
    }

    public function test_exam_search_and_filters(): void
    {
        $this->upload('teacher');
        $this->upload('teacher', ['title' => 'CS101 Final', 'exam_type' => 'final', 'term' => 'Spring 2025', 'year' => 2025]);
        // A teacher's upload is always filed under their own name; only HOD/admin may name another instructor.
        $this->upload('hod', ['course_id' => $this->c2->id, 'title' => 'Design Quiz 1', 'exam_type' => 'quiz', 'year' => 2024, 'instructor_name' => 'Ms. Sara']);

        $titles = fn (array $rows) => array_column($rows, 'title');

        $this->assertCount(3, $this->search());
        $this->assertCount(2, $this->search(['course_id' => $this->c1->id]));
        $this->assertSame(['CS101 Final'], $titles($this->search(['exam_type' => 'final'])));
        $this->assertCount(2, $this->search(['term' => 'fall 2025']));                  // case-insensitive
        $this->assertSame(['Design Quiz 1'], $titles($this->search(['year' => 2024])));
        $this->assertSame(['Design Quiz 1'], $titles($this->search(['q' => 'sara'])));
        $this->assertCount(1, $this->search(['q' => 'SE201']));                          // matches course code
        $this->assertCount(1, $this->search(['limit' => 1]));
        $this->assertCount(1, $this->search(['skip' => 2]));

        // X-Total-Count is the count before skip/limit
        $this->actAs('student');
        $this->getJson('/api/exam-papers?limit=1')->assertHeader('X-Total-Count', '3');
        // newest year first, then newest upload
        $this->assertSame(['CS101 Final', 'CS101 Midterm', 'Design Quiz 1'], $titles($this->search()));
    }

    public function test_search_treats_percent_and_underscore_as_plain_characters(): void
    {
        $this->upload('teacher', ['title' => '100% sure']);
        $this->upload('teacher', ['title' => 'Other']);

        $this->assertCount(1, $this->search(['q' => '%']));
        $this->assertCount(0, $this->search(['q' => '_']));
    }

    public function test_exam_delete_rules_and_file_cleanup(): void
    {
        $paperId = $this->upload('teacher')->json('id');

        $this->actAs('student');
        $this->deleteJson("/api/exam-papers/$paperId")->assertForbidden()
            ->assertExactJson(['detail' => 'Only the uploader or an admin/HOD can delete this paper']);
        $this->actAs('teacher2');
        $this->deleteJson("/api/exam-papers/$paperId")->assertForbidden();
        $this->assertNotEmpty(Storage::disk('local')->allFiles('exam_papers'));

        $this->actAs('hod');
        $this->deleteJson("/api/exam-papers/$paperId")->assertNoContent();
        $this->assertSame([], Storage::disk('local')->allFiles('exam_papers'));

        $this->actAs('student');
        $this->getJson("/api/exam-papers/$paperId")->assertNotFound()->assertExactJson(['detail' => 'Exam paper not found']);
    }

    public function test_uploader_can_delete_own_paper(): void
    {
        $paperId = $this->upload('teacher')->json('id');

        $this->actAs('teacher');
        $this->deleteJson("/api/exam-papers/$paperId")->assertNoContent();
    }

    public function test_upload_size_limit(): void
    {
        config()->set('timetable.uploads.max_mb', 1);   // Python: monkeypatch.setenv("MAX_UPLOAD_MB", "1")
        // a real PDF start, so only the size is wrong
        $big = Files::upload('paper.pdf', Files::pdf().str_repeat('x', 1024 * 1024 + 10));

        $this->bare('teacher', $big)->assertStatus(413)->assertExactJson(['detail' => 'File too large (max 1 MB)']);
        $this->assertSame([], Storage::disk('local')->allFiles('exam_papers'));
    }

    public function test_exam_instructor_name_rule(): void
    {
        // A teacher's paper is filed under their own name; HOD/admin may name another instructor.
        $nameOf = fn (TestResponse $r) => [$r->status(), $r->json('instructor_name')];

        $this->assertSame([201, 'Dr. Teacher'], $nameOf($this->upload('teacher', ['instructor_name' => 'Ms. Sara'])));   // ignored
        $this->assertSame([201, 'Dr. Teacher'], $nameOf($this->upload('teacher', ['instructor_name' => '   '])));         // blank
        $this->assertSame([201, 'Dr. Teacher'], $nameOf($this->upload('teacher', ['instructor_name' => null])));          // omitted
        $this->assertSame([201, 'Dr. Ali'], $nameOf($this->upload('hod', ['instructor_name' => 'Dr. Ali'])));
        $this->assertSame([201, 'Ms. Sara'], $nameOf($this->upload('hod', ['instructor_name' => '  Ms. Sara  '])));       // trimmed
        $this->assertSame([201, 'HOD'], $nameOf($this->upload('hod', ['instructor_name' => ''])));
    }

    public function test_exam_search_finds_paper_by_given_instructor(): void
    {
        $this->upload('hod', ['instructor_name' => 'Ms. Sara']);

        $this->assertSame(['Ms. Sara'], array_column($this->search(['q' => 'sara']), 'instructor_name'));
    }
}
