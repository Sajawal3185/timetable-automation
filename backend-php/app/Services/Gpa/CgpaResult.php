<?php

namespace App\Services\Gpa;

final class CgpaResult
{
    /** @param list<TermResult> $terms */
    public function __construct(
        public readonly ?float $cgpa,
        public readonly float $totalCreditHours,
        public readonly float $totalQualityPoints,
        public readonly array $terms = [],
    ) {}
}
