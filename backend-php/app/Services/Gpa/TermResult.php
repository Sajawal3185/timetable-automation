<?php

namespace App\Services\Gpa;

/** gpa is null when no course counts (for example everything withdrawn). */
final class TermResult
{
    public function __construct(
        public readonly string $term,
        public readonly ?float $gpa,
        public readonly float $creditHours,
        public readonly float $qualityPoints,
    ) {}
}
