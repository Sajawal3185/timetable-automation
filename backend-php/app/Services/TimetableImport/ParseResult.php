<?php

namespace App\Services\TimetableImport;

/** What reading a file gives: the classes, the problems, and which sheets (or PDF pages) were used. */
final class ParseResult
{
    /** @var list<TimetableRow> */
    public array $rows = [];

    /** @var list<ImportIssue> */
    public array $issues = [];

    /** @var list<string> */
    public array $sheetsRead = [];

    /** @var list<string> */
    public array $sheetsSkipped = [];
}
