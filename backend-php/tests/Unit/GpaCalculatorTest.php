<?php

namespace Tests\Unit;

use App\Services\Gpa\CourseGrade;
use App\Services\GpaCalculator as G;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Port of backend/tests/test_gpa_calculator.py, case by case. */
class GpaCalculatorTest extends TestCase
{
    private function cg(?float $credits, string $grade, string $code = ''): CourseGrade
    {
        return new CourseGrade($credits, $grade, $code);
    }

    // ---- grade lookup
    public function test_grade_points_basic(): void
    {
        $this->assertSame(4.0, G::gradeToPoints('A'));
        $this->assertSame(3.33, G::gradeToPoints('B+'));
        $this->assertSame(0.0, G::gradeToPoints('F'));
    }

    public function test_grade_is_case_and_space_insensitive(): void
    {
        $this->assertSame('B+', G::normalizeGrade(' b + '));
        $this->assertSame('A-', G::normalizeGrade('a-'));
    }

    public function test_excluded_grades_have_no_points(): void
    {
        foreach (['W', 'I', 'P'] as $g) {
            $this->assertNull(G::gradeToPoints($g));
        }
    }

    public function test_unknown_grade_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        G::gradeToPoints('Z');
    }

    // ---- rounding
    public function test_round_half_up_not_bankers(): void
    {
        $this->assertSame(3.13, G::round2(3.125));
        $this->assertSame(2.68, G::round2(2.675));
        $this->assertSame(3.0, G::round2(3.0));
    }

    // ---- term GPA
    public function test_term_gpa_weighted_by_credit_hours(): void
    {
        // (3*4.0 + 3*3.0 + 4*2.33) / 10 = 3.032
        $r = G::calculateGpa([$this->cg(3, 'A'), $this->cg(3, 'B'), $this->cg(4, 'C+')], 'Fall 2025');
        $this->assertSame(3.03, $r->gpa);
        $this->assertEquals(10, $r->creditHours);
        $this->assertSame('Fall 2025', $r->term);
    }

    public function test_single_course_gpa_equals_its_points(): void
    {
        $this->assertSame(2.67, G::calculateGpa([$this->cg(3, 'B-')])->gpa);
    }

    public function test_fail_counts_as_zero_points_but_counts_credits(): void
    {
        $this->assertSame(2.0, G::calculateGpa([$this->cg(3, 'A'), $this->cg(3, 'F')])->gpa);
    }

    public function test_withdrawn_course_is_ignored(): void
    {
        $r = G::calculateGpa([$this->cg(3, 'A'), $this->cg(3, 'W')]);
        $this->assertSame(4.0, $r->gpa);
        $this->assertEquals(3, $r->creditHours);
    }

    public function test_term_with_only_excluded_grades_has_no_gpa(): void
    {
        $r = G::calculateGpa([$this->cg(3, 'W'), $this->cg(3, 'I')]);
        $this->assertNull($r->gpa);
        $this->assertEquals(0, $r->creditHours);
    }

    public function test_empty_term_has_no_gpa(): void
    {
        $this->assertNull(G::calculateGpa([])->gpa);
    }

    public function test_credit_hours_must_be_positive(): void
    {
        foreach ([0.0, -3.0, null] as $bad) {
            try {
                G::calculateGpa([$this->cg($bad, 'A')]);
                $this->fail('expected an exception for '.var_export($bad, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- CGPA
    public function test_cgpa_is_weighted_not_average_of_gpas(): void
    {
        $r = G::calculateCgpa([['T1', [$this->cg(3, 'A')]], ['T2', [$this->cg(6, 'C')]]]);
        // (12 + 12) / 9 = 2.67 (a plain average of 4.0 and 2.0 would wrongly give 3.0)
        $this->assertSame(2.67, $r->cgpa);
        $this->assertSame([4.0, 2.0], array_map(fn ($t) => $t->gpa, $r->terms));
        $this->assertEquals(9, $r->totalCreditHours);
    }

    public function test_cgpa_with_one_term_equals_term_gpa(): void
    {
        $r = G::calculateCgpa([['T1', [$this->cg(3, 'A'), $this->cg(3, 'B')]]]);
        $this->assertSame(3.5, $r->cgpa);
        $this->assertSame($r->cgpa, $r->terms[0]->gpa);
    }

    public function test_cgpa_on_top_of_previous_record(): void
    {
        // previous 3.0 over 60 credits + 3 credits of A: (180 + 12) / 63 = 3.0476...
        $r = G::calculateCgpa([['T1', [$this->cg(3, 'A')]]], 3.0, 60.0);
        $this->assertSame(3.05, $r->cgpa);
        $this->assertEquals(63, $r->totalCreditHours);
    }

    public function test_previous_values_must_come_together(): void
    {
        $terms = [['T1', [$this->cg(3, 'A')]]];
        foreach ([[3.0, null], [null, 30.0]] as [$cgpa, $hours]) {
            try {
                G::calculateCgpa($terms, $cgpa, $hours);
                $this->fail('expected an exception');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_previous_cgpa_range_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        G::calculateCgpa([['T1', [$this->cg(3, 'A')]]], 4.5, 30.0);
    }

    public function test_no_counted_credits_means_no_cgpa(): void
    {
        $this->assertNull(G::calculateCgpa([['T1', [$this->cg(3, 'W')]]])->cgpa);
    }

    // ---- repeated courses
    private function repeat(): array
    {
        return [['T1', [$this->cg(3, 'F', 'CS101')]], ['T2', [$this->cg(3, 'B', 'CS101')]]];
    }

    public function test_repeat_policy_all_counts_every_attempt(): void
    {
        $this->assertSame(1.5, G::calculateCgpa($this->repeat(), null, null, 'all')->cgpa);   // (0 + 9) / 6
    }

    public function test_repeat_policy_latest_counts_newest_attempt(): void
    {
        $this->assertSame(3.0, G::calculateCgpa($this->repeat(), null, null, 'latest')->cgpa);
    }

    public function test_repeat_policy_best_counts_highest_grade(): void
    {
        $reverse = [['T1', [$this->cg(3, 'B', 'CS101')]], ['T2', [$this->cg(3, 'D', 'CS101')]]];
        $this->assertSame(3.0, G::calculateCgpa($reverse, null, null, 'best')->cgpa);
        $this->assertSame(1.0, G::calculateCgpa($reverse, null, null, 'latest')->cgpa);
    }

    public function test_later_withdrawal_does_not_erase_a_real_grade(): void
    {
        $terms = [['T1', [$this->cg(3, 'B', 'CS101')]], ['T2', [$this->cg(3, 'W', 'CS101')]]];
        $this->assertSame(3.0, G::calculateCgpa($terms, null, null, 'latest')->cgpa);
    }

    public function test_repeat_detection_ignores_course_code_case(): void
    {
        $terms = [['T1', [$this->cg(3, 'F', 'cs101')]], ['T2', [$this->cg(3, 'A', 'CS101')]]];
        $this->assertSame(4.0, G::calculateCgpa($terms, null, null, 'latest')->cgpa);
    }

    public function test_courses_without_code_are_never_merged(): void
    {
        $terms = [['T1', [$this->cg(3, 'A')]], ['T2', [$this->cg(3, 'C')]]];
        $this->assertSame(3.0, G::calculateCgpa($terms, null, null, 'latest')->cgpa);
    }
}
