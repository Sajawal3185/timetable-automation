<?php

namespace App\Services\TimetableImport;

/** What TimetableLoader wrote. */
final class LoadSummary
{
    public int $entriesCreated = 0;

    public int $entriesSkippedExisting = 0;

    public int $departmentsCreated = 0;

    public int $sectionsCreated = 0;

    public int $coursesCreated = 0;

    public int $teachersCreated = 0;

    /** @var list<string> rooms made with unknown capacity, for an admin to fix */
    public array $roomsCreated = [];
}
