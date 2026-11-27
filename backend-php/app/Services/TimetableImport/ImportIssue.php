<?php

namespace App\Services\TimetableImport;

/** A problem found in the file. "error" skips the row, "warning" keeps it. */
final class ImportIssue
{
    public function __construct(
        public string $sheet,
        public int $row,
        public string $message,
        public string $level = 'error',
    ) {}

    /** ImportIssueOut in schemas/portal.py. */
    public function toArray(): array
    {
        return ['sheet' => $this->sheet, 'row' => $this->row, 'message' => $this->message, 'level' => $this->level];
    }
}
