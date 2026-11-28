<?php

namespace App\Services\Gpa;

/** One course attempt (backend/app/services/gpa_calculator.py: CourseGrade). */
final class CourseGrade
{
    public function __construct(
        public readonly ?float $creditHours,
        public readonly string $grade,
        public readonly string $courseCode = '',
        public readonly string $courseTitle = '',
    ) {}
}
