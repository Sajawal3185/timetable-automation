<?php

namespace App\Services;

use App\Services\Gpa\CgpaResult;
use App\Services\Gpa\CourseGrade;
use App\Services\Gpa\TermResult;
use InvalidArgumentException;

/**
 * Port of backend/app/services/gpa_calculator.py: pure functions, no database, no HTTP.
 * Python's ValueError becomes InvalidArgumentException (the controller turns it into a 422).
 * Edit ONLY GRADE_POINTS if the university scale changes, exactly as in Python.
 */
final class GpaCalculator
{
    public const GRADE_POINTS = [
        'A' => 4.00, 'A-' => 3.67,
        'B+' => 3.33, 'B' => 3.00, 'B-' => 2.67,
        'C+' => 2.33, 'C' => 2.00, 'C-' => 1.67,
        'D+' => 1.33, 'D' => 1.00,
        'F' => 0.00,
    ];

    /** Grades with no points that are left out of GPA (withdrawn, incomplete, pass). */
    public const EXCLUDED_GRADES = ['W', 'I', 'P', 'NC', 'AUD'];

    /** strip().upper().replace(" ", "") then must be a known grade. */
    public static function normalizeGrade(?string $grade): string
    {
        $g = str_replace(' ', '', mb_strtoupper(self::strip($grade ?? '')));
        if (isset(self::GRADE_POINTS[$g]) || in_array($g, self::EXCLUDED_GRADES, true)) {
            return $g;
        }

        // Same text as Python: sorted(GRADE_POINTS) + sorted(EXCLUDED_GRADES), printed as a list.
        $scale = array_keys(self::GRADE_POINTS);
        $excluded = self::EXCLUDED_GRADES;
        sort($scale, SORT_STRING);
        sort($excluded, SORT_STRING);
        $allowed = '['.implode(', ', array_map(fn ($x) => "'$x'", [...$scale, ...$excluded])).']';

        throw new InvalidArgumentException('Unknown grade: '."'".($grade ?? '')."'".". Allowed: $allowed");
    }

    /** Grade points for a letter grade, or null if the grade is excluded. */
    public static function gradeToPoints(string $grade): ?float
    {
        $g = self::normalizeGrade($grade);

        return in_array($g, self::EXCLUDED_GRADES, true) ? null : self::GRADE_POINTS[$g];
    }

    /**
     * Round half up to 2 decimals. Works on the printed digits, so the answer does not
     * depend on the PHP version's round() rules (they changed in 8.4).
     */
    public static function round2(float $value): float
    {
        $sign = $value < 0 ? -1 : 1;
        [$int, $frac] = explode('.', sprintf('%.12F', abs($value)));
        $cents = (int) ($int.substr($frac, 0, 2));
        if ((int) $frac[2] >= 5) {
            $cents++;
        }

        return $sign * ($cents / 100);
    }

    /** GPA = sum(credit_hours * grade_points) / sum(credit_hours). */
    public static function calculateGpa(array $courses, string $term = ''): TermResult
    {
        [$credits, $quality] = self::sumPoints($courses);
        $gpa = $credits > 0 ? self::round2($quality / $credits) : null;

        return new TermResult($term, $gpa, $credits, self::round2($quality));
    }

    /**
     * CGPA over all terms, optionally on top of an earlier CGPA ("what if" planning).
     * repeatPolicy: "all" counts every attempt, "latest" only the newest attempt of a
     * repeated course, "best" the highest grade.
     *
     * @param  list<array{0:string,1:list<CourseGrade>}>  $terms  [label, courses] pairs
     */
    public static function calculateCgpa(
        array $terms,
        ?float $previousCgpa = null,
        ?float $previousCreditHours = null,
        string $repeatPolicy = 'all',
    ): CgpaResult {
        if (($previousCgpa === null) !== ($previousCreditHours === null)) {
            throw new InvalidArgumentException('previous_cgpa and previous_credit_hours must be given together');
        }
        if ($previousCgpa !== null) {
            if ($previousCgpa < 0 || $previousCgpa > 4) {
                throw new InvalidArgumentException('previous_cgpa must be between 0 and 4');
            }
            if ($previousCreditHours < 0) {
                throw new InvalidArgumentException('previous_credit_hours cannot be negative');
            }
        }

        $termResults = array_map(fn (array $t) => self::calculateGpa($t[1], $t[0]), $terms);

        [$credits, $quality] = self::sumPoints(self::applyRepeatPolicy($terms, $repeatPolicy));
        if ($previousCgpa !== null) {
            $credits += $previousCreditHours;
            $quality += $previousCgpa * $previousCreditHours;
        }
        $cgpa = $credits > 0 ? self::round2($quality / $credits) : null;

        return new CgpaResult($cgpa, $credits, self::round2($quality), array_values($termResults));
    }

    /** @param iterable<CourseGrade> $courses  @return array{0:float,1:float} */
    private static function sumPoints(iterable $courses): array
    {
        $credits = $quality = 0.0;
        foreach ($courses as $c) {
            self::validateCredit($c->creditHours);
            $pts = self::gradeToPoints($c->grade);
            if ($pts === null) {
                continue;
            }
            $credits += $c->creditHours;
            $quality += $c->creditHours * $pts;
        }

        return [$credits, $quality];
    }

    private static function validateCredit(?float $creditHours): void
    {
        if ($creditHours === null || $creditHours <= 0) {
            // VERIFY: Python prints repr(): None, 0.0, -3.0
            $shown = $creditHours === null ? 'None' : (floor($creditHours) == $creditHours ? number_format($creditHours, 1, '.', '') : (string) $creditHours);
            throw new InvalidArgumentException("credit_hours must be greater than 0, got $shown");
        }
    }

    /** @return list<CourseGrade> */
    private static function applyRepeatPolicy(array $terms, string $policy): array
    {
        $flat = [];
        foreach ($terms as $t) {
            foreach ($t[1] as $c) {
                $flat[] = $c;
            }
        }
        if ($policy === 'all') {
            return $flat;
        }

        $chosen = [];
        $result = [];
        foreach ($flat as $c) {
            $key = mb_strtoupper(self::strip($c->courseCode));
            if ($key === '') {   // no code: a repeat cannot be detected, keep the course
                $result[] = $c;

                continue;
            }
            $old = $chosen[$key] ?? null;
            if ($old === null) {
                $chosen[$key] = $c;
            } elseif ($policy === 'latest') {
                if (self::gradeToPoints($c->grade) !== null) {   // a later W must not wipe a real grade
                    $chosen[$key] = $c;
                }
            } elseif ($policy === 'best') {
                $newP = self::gradeToPoints($c->grade);
                $oldP = self::gradeToPoints($old->grade);
                if ($newP !== null && ($oldP === null || $newP > $oldP)) {
                    $chosen[$key] = $c;
                }
            }
        }

        return [...$result, ...array_values($chosen)];
    }

    /** Python str.strip(): also removes no-break and ideographic spaces. */
    private static function strip(string $s): string
    {
        return preg_replace('/^[\s\x{A0}\x{3000}]+|[\s\x{A0}\x{3000}]+$/u', '', $s) ?? $s;
    }
}
