<?php

namespace Tests\Unit;

use App\Support\InstructorName;
use Tests\TestCase;

/** Port of backend/tests/test_instructor_name.py. */
class InstructorNameTest extends TestCase
{
    private object $teacher;

    private object $hod;

    private object $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = (object) ['name' => 'Dr. Teacher', 'role' => 'teacher'];
        $this->hod = (object) ['name' => 'HOD', 'role' => 'hod'];
        $this->admin = (object) ['name' => 'Admin', 'role' => 'Admin'];   // role check ignores case
    }

    public function test_hod_and_admin_given_name_is_used_and_trimmed(): void
    {
        $this->assertSame('Ms. Sara', InstructorName::resolve('Ms. Sara', $this->hod));
        $this->assertSame('Ms. Sara', InstructorName::resolve('  Ms. Sara  ', $this->admin));
    }

    public function test_teacher_cannot_name_another_instructor(): void
    {
        $this->assertSame('Dr. Teacher', InstructorName::resolve('Ms. Sara', $this->teacher));
    }

    public function test_missing_or_blank_name_falls_back_to_uploader(): void
    {
        foreach ([null, '', '   '] as $given) {
            $this->assertSame('Dr. Teacher', InstructorName::resolve($given, $this->teacher));
            $this->assertSame('HOD', InstructorName::resolve($given, $this->hod));
        }
    }

    public function test_uploader_without_a_name_is_unknown(): void
    {
        $this->assertSame('Unknown', InstructorName::resolve(null, (object) ['name' => '', 'role' => 'hod']));
        $this->assertSame('Unknown', InstructorName::resolve(null, (object) ['name' => '  ']));
        $this->assertSame('Unknown', InstructorName::resolve('Ms. Sara', (object) ['role' => 'teacher']));
    }

    public function test_a_no_break_space_counts_as_blank_like_python_strip(): void
    {
        $this->assertSame('HOD', InstructorName::resolve(mb_chr(0xA0).mb_chr(0x3000), $this->hod));
    }
}
