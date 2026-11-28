<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\Support\Files;
use Tests\Support\SeedsArchive;
use Tests\TestCase;

/** Port of backend/tests/test_materials_api.py: the lecture material archive. */
class MaterialsApiTest extends TestCase
{
    use SeedsArchive;

    private const JSON = ['Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedArchive();
    }

    /** upload_material(): always sends week1.pdf; a null value leaves that form field out. */
    private function upload(string $who, array $over = []): TestResponse
    {
        $this->actAs($who);
        $data = array_filter([
            'course_id' => $this->c1->id, 'title' => 'Week 1 slides', 'material_type' => 'slides', ...$over,
        ], fn ($value) => $value !== null);

        return $this->post('/api/lecture-materials', [...$data, 'file' => Files::upload('week1.pdf', Files::pdf())], self::JSON);
    }

    private function list(array $params = []): array
    {
        $this->actAs('student');

        return $this->getJson('/api/lecture-materials?'.http_build_query($params))->assertOk()->json();
    }

    public function test_materials_grouped_by_instructor(): void
    {
        // Only HOD/admin may file material under another instructor's name (a teacher's
        // upload is always filed under their own name, see the test below).
        $this->upload('hod', ['instructor_name' => 'Dr. Ali']);
        $this->upload('hod', ['title' => 'Week 2 notes', 'material_type' => 'notes', 'instructor_name' => 'Dr. Ali']);
        $this->upload('admin', ['title' => 'My slides', 'instructor_name' => 'Ms. Sara']);
        $this->upload('teacher', ['course_id' => $this->c2->id, 'title' => 'Other course']);

        $this->actAs('student');
        $body = $this->getJson("/api/lecture-materials/by-course/{$this->c1->id}")->assertOk()->json();

        $this->assertSame('CS101', $body['course_code']);
        $this->assertSame(
            [['Dr. Ali', 2], ['Ms. Sara', 1]],
            array_map(fn ($g) => [$g['instructor_name'], count($g['materials'])], $body['instructors']),
        );
        $this->getJson('/api/lecture-materials/by-course/999')->assertNotFound()->assertExactJson(['detail' => 'Course not found']);
    }

    public function test_instructor_groups_ignore_case_and_spaces(): void
    {
        $this->upload('hod', ['instructor_name' => 'Dr. Ali']);
        $this->upload('hod', ['title' => 'Again', 'instructor_name' => 'DR. ALI']);   // same person, written differently

        $this->actAs('student');
        $groups = $this->getJson("/api/lecture-materials/by-course/{$this->c1->id}")->json('instructors');

        $this->assertCount(1, $groups);
        $this->assertSame('Dr. Ali', $groups[0]['instructor_name']);
        $this->assertCount(2, $groups[0]['materials']);
    }

    public function test_material_defaults_instructor_to_uploader_and_filters(): void
    {
        $this->assertSame('Dr. Teacher', $this->upload('teacher')->json('instructor_name'));
        // a teacher cannot file material under someone else's name
        $this->assertSame('Dr. Teacher', $this->upload('teacher', ['title' => 'Spoof', 'instructor_name' => 'Dr. Ali'])->json('instructor_name'));
        $this->upload('teacher2', ['title' => 'Notes', 'material_type' => 'notes', 'term' => 'Fall 2025']);

        $this->assertCount(3, $this->list());
        $this->assertSame(['Notes'], array_column($this->list(['material_type' => 'notes']), 'title'));
        $this->assertCount(1, $this->list(['instructor_name' => 'dr. oth']));
        $this->assertCount(1, $this->list(['term' => 'fall 2025']));
        $this->assertCount(1, $this->list(['q' => 'notes']));

        $this->actAs('student');
        $this->getJson('/api/lecture-materials?limit=2')->assertHeader('X-Total-Count', '3');
    }

    public function test_material_permissions_download_delete(): void
    {
        $this->upload('student')->assertForbidden();
        $materialId = $this->upload('teacher')->json('id');

        $this->actAs('student');
        $this->assertSame(Files::pdf(), $this->get("/api/lecture-materials/$materialId/download")->streamedContent());

        $this->actAs('teacher2');
        $this->deleteJson("/api/lecture-materials/$materialId")->assertForbidden()
            ->assertExactJson(['detail' => 'Only the uploader or an admin/HOD can delete this material']);
        $this->actAs('admin');
        $this->deleteJson("/api/lecture-materials/$materialId")->assertNoContent();
        $this->actAs('student');
        $this->getJson("/api/lecture-materials/$materialId")->assertNotFound()->assertExactJson(['detail' => 'Material not found']);

        // slides are allowed here (not in exam papers)
        $this->actAs('teacher');
        $this->post('/api/lecture-materials', [
            'course_id' => $this->c1->id, 'title' => 'ppt', 'material_type' => 'slides',
            'file' => Files::upload('a.pptx', Files::pptx()),
        ], self::JSON)->assertCreated();
    }

    public function test_material_instructor_name_rule(): void
    {
        // A teacher's file is filed under their own name; HOD/admin may name another instructor.
        $nameOf = fn (TestResponse $r) => [$r->status(), $r->json('instructor_name')];

        $this->assertSame([201, 'Dr. Teacher'], $nameOf($this->upload('teacher', ['instructor_name' => 'Ms. Sara'])));   // ignored
        $this->assertSame([201, 'Dr. Teacher'], $nameOf($this->upload('teacher')));                                        // omitted
        $this->assertSame([201, 'Dr. Teacher'], $nameOf($this->upload('teacher', ['instructor_name' => '   '])));         // blank
        $this->assertSame([201, 'Dr. Ali'], $nameOf($this->upload('admin', ['instructor_name' => 'Dr. Ali'])));
        // The Python file you sent was cut off here; these mirror the exam-paper test. Compare with its last lines.
        $this->assertSame([201, 'Ms. Sara'], $nameOf($this->upload('hod', ['instructor_name' => '  Ms. Sara  '])));       // trimmed
        $this->assertSame([201, 'HOD'], $nameOf($this->upload('hod', ['instructor_name' => ''])));
    }
}
