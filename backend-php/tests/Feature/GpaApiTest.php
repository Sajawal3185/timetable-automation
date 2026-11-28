<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Port of backend/tests/test_gpa_api.py. Real tokens through /api/auth/login, like AuthApiTest.
 * (Like AuthApiTest this has no RefreshDatabase line: if your other feature tests have one, add it.)
 */
class GpaApiTest extends TestCase
{
    private const A = 'se-111111';

    private const B = 'se-222222';

    /** @var array<string,string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->role('student')->create(['identifier' => self::A, 'name' => 'STUDENT A']);
        User::factory()->role('student')->create(['identifier' => self::B, 'name' => 'STUDENT B']);
    }

    /** Acts as that user (token fetched once, so the login limit is never hit). */
    private function as(string $identifier): static
    {
        $this->tokens[$identifier] ??= $this->postJson('/api/auth/login', [
            'identifier' => $identifier, 'password' => UserFactory::PASSWORD,
        ])->json('token');

        return $this->withToken($this->tokens[$identifier]);
    }

    /** term("T1", [3, "A"], [3, "F", "CS1"]) as in the Python helper. */
    private function term(string $name, array ...$courses): array
    {
        return ['term' => $name, 'courses' => array_map(fn (array $c) => [
            'credit_hours' => $c[0], 'grade' => $c[1], ...(isset($c[2]) ? ['course_code' => $c[2]] : []),
        ], $courses)];
    }

    private function record(string $who, string $term, string $code, $credits, string $grade): TestResponse
    {
        return $this->as($who)->postJson('/api/gpa/records', [
            'term' => $term, 'course_code' => $code, 'credit_hours' => $credits, 'grade' => $grade,
        ]);
    }

    public function test_every_gpa_endpoint_needs_a_login(): void
    {
        $this->getJson('/api/gpa/grade-scale')->assertStatus(401);
        $this->postJson('/api/gpa/calculate', ['terms' => []])->assertStatus(401);
        $this->getJson('/api/gpa/records')->assertStatus(401);
        $this->getJson('/api/gpa/summary')->assertStatus(401);
    }

    public function test_gpa_calculate_endpoint(): void
    {
        $r = $this->as(self::A)->postJson('/api/gpa/calculate', [
            'terms' => [$this->term('T1', [3, 'A']), $this->term('T2', [6, 'C'])],
        ])->assertOk();

        $body = $r->json();
        $this->assertEquals(2.67, $body['cgpa']);
        $this->assertEquals(9, $body['total_credit_hours']);
        $this->assertEquals([4.0, 2.0], array_column($body['terms'], 'gpa'));
        $this->assertSame(['cgpa', 'total_credit_hours', 'total_quality_points', 'terms'], array_keys($body));
        $this->assertSame(['term', 'gpa', 'credit_hours', 'quality_points'], array_keys($body['terms'][0]));
    }

    public function test_gpa_calculate_options_and_errors(): void
    {
        $post = fn (array $json) => $this->as(self::A)->postJson('/api/gpa/calculate', $json);

        $prev = $post(['terms' => [$this->term('T1', [3, 'A'])], 'previous_cgpa' => 3.0, 'previous_credit_hours' => 60]);
        $this->assertEquals(3.05, $prev->json('cgpa'));

        $rep = $post([
            'terms' => [$this->term('T1', [3, 'F', 'CS1']), $this->term('T2', [3, 'B', 'CS1'])],
            'repeat_policy' => 'latest',
        ]);
        $this->assertEquals(3.0, $rep->json('cgpa'));

        $post(['terms' => [$this->term('T1', [3, 'Q'])]])->assertStatus(422);
        $post(['terms' => [$this->term('T1', [0, 'A'])]])->assertStatus(422);
        $post(['terms' => [$this->term('T1', [3, 'A'])], 'previous_cgpa' => 3.0])->assertStatus(422);
        $post(['terms' => []])->assertStatus(422);
        $this->assertNull($post(['terms' => [$this->term('T1', [3, 'W'])]])->json('cgpa'));
    }

    public function test_grade_scale_endpoint(): void
    {
        $body = $this->as(self::A)->getJson('/api/gpa/grade-scale')->assertOk()->json();

        $this->assertEquals(4.0, $body['grade_points']['A']);
        $this->assertContains('W', $body['excluded_grades']);
        $this->assertSame(['AUD', 'I', 'NC', 'P', 'W'], $body['excluded_grades']);
    }

    public function test_saved_records_and_summary(): void
    {
        $this->record(self::A, 'Fall 2025', 'cs101', 3, 'a')->assertStatus(201);
        $this->record(self::A, 'Fall 2025', 'MT101', 3, 'B')->assertStatus(201);
        $this->record(self::A, 'Spring 2026', 'CS102', 3, 'c')->assertStatus(201);
        $this->record(self::A, 'Fall 2025', 'CS101', 3, 'A')->assertStatus(409);   // duplicate (code is upper-cased)
        $this->record(self::A, 'Fall 2025', 'XX1', 3, 'Q')->assertStatus(422);

        $records = $this->as(self::A)->getJson('/api/gpa/records')->assertOk()->json();
        $this->assertSame(['CS101', 'MT101', 'CS102'], array_column($records, 'course_code'));
        $this->assertSame('A', $records[0]['grade']);

        $summary = $this->as(self::A)->getJson('/api/gpa/summary')->assertOk()->json();
        $this->assertSame(['Fall 2025', 'Spring 2026'], array_column($summary['terms'], 'term'));
        $this->assertEquals(3.5, $summary['terms'][0]['gpa']);
        $this->assertEquals(3.0, $summary['cgpa']);   // (12 + 9 + 6) / 9
    }

    public function test_records_are_private_per_student(): void
    {
        $id = $this->record(self::A, 'T', 'C1', 3, 'A')->assertStatus(201)->json('id');

        $this->assertSame([], $this->as(self::B)->getJson('/api/gpa/records')->json());
        $this->as(self::B)->deleteJson("/api/gpa/records/$id")->assertStatus(404)
            ->assertExactJson(['detail' => 'Record not found']);
        $this->assertCount(1, $this->as(self::A)->getJson('/api/gpa/records')->json());
        $this->as(self::A)->deleteJson("/api/gpa/records/$id")->assertStatus(204);
        $this->assertNull($this->as(self::A)->getJson('/api/gpa/summary')->json('cgpa'));
    }

    public function test_summary_repeat_policy(): void
    {
        foreach ([['T1', 'F'], ['T2', 'B']] as [$term, $grade]) {
            $this->record(self::A, $term, 'CS1', 3, $grade);
        }

        $this->assertEquals(1.5, $this->as(self::A)->getJson('/api/gpa/summary')->json('cgpa'));
        $this->assertEquals(3.0, $this->as(self::A)->getJson('/api/gpa/summary?repeat_policy=latest')->json('cgpa'));
        $this->as(self::A)->getJson('/api/gpa/summary?repeat_policy=nope')->assertStatus(422);
    }

    public function test_terms_are_sorted_by_year_then_season(): void
    {
        // Extra case for the Python _term_sort_key: saved out of order, shown in time order.
        $this->record(self::A, 'fall 2025', 'A1', 3, 'A');
        $this->record(self::A, 'Spring 2025', 'B1', 3, 'B');
        $this->record(self::A, 'Spring 2026', 'C1', 3, 'C');

        $terms = $this->as(self::A)->getJson('/api/gpa/summary')->json('terms');
        $this->assertSame(['Spring 2025', 'Fall 2025', 'Spring 2026'], array_column($terms, 'term'));
    }
}
