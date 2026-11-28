<?php

namespace Tests\Support;

use App\Models\Course;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * conftest.py's seed for the archive tests: two courses and one account per role
 * (two teachers, so "someone else's file" can be tested), uploads on a fake disk.
 */
trait SeedsArchive
{
    protected Course $c1;

    protected Course $c2;

    /** @var array<string, User> */
    protected array $people = [];

    protected function seedArchive(): void
    {
        Storage::fake('local');

        $dept = Department::factory()->create(['name' => 'Software Engineering', 'code' => 'SE']);
        $this->c1 = Course::factory()->create(['code' => 'CS101', 'title' => 'Programming Fundamentals', 'department_id' => $dept->id]);
        $this->c2 = Course::factory()->create(['code' => 'SE201', 'title' => 'Software Design', 'department_id' => $dept->id]);

        $accounts = [
            'student' => ['Student', 'student'],
            'teacher' => ['Dr. Teacher', 'teacher'],
            'teacher2' => ['Dr. Other', 'teacher'],
            'hod' => ['HOD', 'hod'],
            'admin' => ['Admin', 'admin'],
        ];
        foreach ($accounts as $key => [$name, $role]) {
            $this->people[$key] = User::factory()->role($role)->create([
                'name' => $name,
                'identifier' => "archive-$key",
                ...($role === 'hod' ? ['department_id' => $dept->id] : []),
            ]);
        }
    }

    /** Make $who the logged-in user for the next requests. */
    protected function actAs(string $who): void
    {
        $this->asUser($this->people[$who]);
    }
}
