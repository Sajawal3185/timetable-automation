<?php

namespace Tests\Feature;

use App\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The CHECK constraints the migrations add on MySQL (Aiven, and CI's MySQL job).
 * SQLite can't add them after CREATE TABLE, so there these tests are skipped.
 * Rows go in with DB::table, past the models, the way a bad copy or a bug would.
 */
class CheckConstraintsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints exist on MySQL only (CI runs them in the mysql job).');
        }
    }

    public function test_valid_rows_go_in(): void
    {
        $this->insertSection(1);
        $this->insertSection(null);
        foreach (['student', 'teacher', 'hod', 'admin'] as $role) {
            $this->insertUser($role);
        }

        $this->assertSame(4, DB::table('users')->count());
    }

    #[DataProvider('badStudentCounts')]
    public function test_student_count_must_be_positive(int $count): void
    {
        $this->expectViolation('ck_sections_student_count_positive');
        $this->insertSection($count);
    }

    public static function badStudentCounts(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    #[DataProvider('badRoles')]
    public function test_role_must_be_a_known_lowercase_role(string $role): void
    {
        $this->expectViolation('ck_users_user_role_lowercase');
        $this->insertUser($role);
    }

    public static function badRoles(): array
    {
        // 'Admin' is the case-sensitivity check: the default collation alone would let it in.
        return ['unknown' => ['x'], 'capitalised' => ['Admin'], 'upper' => ['HOD'], 'empty' => ['']];
    }

    private function expectViolation(string $constraint): void
    {
        $this->expectException(QueryException::class);
        // MySQL error 3819: "Check constraint '<name>' is violated."
        $this->expectExceptionMessageMatches("/3819.*$constraint/s");
    }

    private function insertSection(?int $studentCount): void
    {
        static $n = 0;
        DB::table('sections')->insert([
            'name' => 'S'.++$n, 'department_id' => Department::factory()->create()->id,
            'student_count' => $studentCount,
        ]);
    }

    private function insertUser(string $role): void
    {
        DB::table('users')->insert(['name' => 'X', 'identifier' => 'u-'.uniqid(), 'role' => $role]);
    }
}
