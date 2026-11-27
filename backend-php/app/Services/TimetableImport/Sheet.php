<?php

namespace App\Services\TimetableImport;

/**
 * One table read from a file: an Excel sheet, a CSV file, or a PDF page.
 * $cells is a grid of plain strings, row by row; a cell may hold several
 * lines ("Course\nTeacher\nRoom"). $issues are warnings the reader found
 * (for example a PDF page with no section name on it).
 */
final class Sheet
{
    /**
     * @param  list<list<string>>  $cells
     * @param  list<ImportIssue>  $issues
     */
    public function __construct(
        public string $name,
        public array $cells,
        public array $issues = [],
    ) {}
}
