<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Files;
use Tests\Support\SeedsArchive;
use Tests\TestCase;

/**
 * Port of the second half of backend/tests/test_file_content.py: the content check
 * through the API. Needs BOTH the materials (W2) and exam paper (W3) routes, so it
 * goes in the exam papers PR.
 */
class FileContentApiTest extends TestCase
{
    use SeedsArchive;

    private const JSON = ['Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedArchive();
    }

    private function exam(string $name, string $data): TestResponse
    {
        $this->actAs('teacher');

        return $this->post('/api/exam-papers', [
            'course_id' => $this->c1->id, 'title' => 't', 'term' => 'Fall 2025', 'exam_type' => 'final',
            'file' => Files::upload($name, $data),
        ], self::JSON);
    }

    private function material(string $name, string $data): TestResponse
    {
        $this->actAs('teacher');

        return $this->post('/api/lecture-materials', [
            'course_id' => $this->c1->id, 'title' => 't', 'material_type' => 'slides',
            'file' => Files::upload($name, $data),
        ], self::JSON);
    }

    public function test_exam_upload_refuses_disguised_file_and_leaves_nothing_behind(): void
    {
        foreach ([['fake.pdf', Files::exe()], ['fake.pdf', Files::png()], ['fake.png', Files::pdf()], ['fake.docx', 'plain text']] as [$name, $data]) {
            $response = $this->exam($name, $data)->assertStatus(400);
            $this->assertStringContainsString('content', $response->json('detail'), $name);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('exam_papers'));   // refused files are not kept
        $this->actAs('student');
        $this->assertSame([], $this->getJson('/api/exam-papers')->json());
    }

    public function test_exam_upload_accepts_real_png(): void
    {
        $this->exam('scan.png', Files::png())->assertCreated();
    }

    public function test_material_upload_checks_content_for_every_type(): void
    {
        $this->assertSame(201, $this->material('slides.pptx', Files::pptx())->status());
        $this->assertSame(201, $this->material('notes.txt', 'week 1 notes')->status());
        $this->assertSame(400, $this->material('slides.pptx', Files::pdf())->status());          // a PDF renamed .pptx
        $this->assertSame(400, $this->material('bundle.zip', Files::exe())->status());           // an .exe renamed .zip
        $this->assertSame(400, $this->material('notes.txt', Files::exe()."\x00\x00")->status());
        $this->assertCount(2, Storage::disk('local')->allFiles('lecture_materials'));            // only the two good ones
    }

    public function test_downloads_are_attachments(): void
    {
        $paperId = $this->exam('paper.pdf', Files::pdf())->json('id');
        $materialId = $this->material('week1.pdf', Files::pdf())->json('id');

        $this->actAs('student');
        foreach (["/api/exam-papers/$paperId/download", "/api/lecture-materials/$materialId/download"] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertStringStartsWith('attachment', $response->headers->get('content-disposition'), $url);
        }
    }
}
