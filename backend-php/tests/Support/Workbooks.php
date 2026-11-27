<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Real .xlsx files for the import tests (the sheet builders of test_timetable_import.py). */
final class Workbooks
{
    /**
     * @param  array<string, list<list<string|null>>>  $sheets  title => rows
     * @param  array<string, list<string>>  $merges  title => ["A2:A3", ...]
     * @param  array<string, array<string, string>>  $formats  title => ["B2:C2" => "h:mm"]
     * @return string the file's bytes
     */
    public static function xlsx(array $sheets, array $merges = [], array $formats = []): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $ws = $book->createSheet();
            $ws->setTitle($title);
            $ws->fromArray($rows, null, 'A1', true);
            foreach ($merges[$title] ?? [] as $range) {
                $ws->mergeCells($range);
            }
            foreach ($formats[$title] ?? [] as $range => $format) {
                $ws->getStyle($range)->getNumberFormat()->setFormatCode($format);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'tt').'.xlsx';
        (new Xlsx($book))->save($path);
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /** test_timetable_import.py make_flat: a title row, 5 classes (2 bad), and an Instructions sheet. */
    public static function flat(): string
    {
        return self::xlsx([
            'BSSE-4A' => [
                ['Some title row that is not the header'],
                ['Day', 'Time', 'Course', 'Teacher', 'Room'],
                ['Monday', '08:30 - 10:00', 'SE201 Software Design', 'Dr. Ali', 'R-2'],
                ['', '10:00 - 11:30', 'CS101 Programming Fundamentals', 'Ms. Sara', 'R-1'],   // blank day = Monday
                ['Tuesday', '8:30-10:00', 'SE201 Software Design', 'Dr. Ali', 'R-2'],
                ['Tuesday', 'bad time', 'SE201 Software Design', 'Dr. Ali', 'R-2'],
                ['Funday', '08:30 - 10:00', 'SE201 Software Design', 'Dr. Ali', 'R-2'],
            ],
            'Instructions' => [['Read me']],
        ]);
    }

    /** make_grid: days down the side, three slots across (or the reverse). */
    public static function grid(bool $transposed = false): string
    {
        $grid = [
            ['Day', '08:30 - 10:00', '10:00 - 11:30', '11:30 - 1:00'],
            ['Monday', "AI101 Intro to AI\nDr. Noor\nR-1", 'Break', "Math\nMs. Zara\nR-2"],
            ['Tuesday', '', "AI101 Intro to AI\nDr. Noor\nR-1", ''],
        ];
        if ($transposed) {
            $grid = array_map(null, ...$grid);
        }

        return self::xlsx(['BSAI-2B' => $grid]);
    }

    /** test_timetable_api.py flat_xlsx_bytes: 2 good rows, 1 bad, one new room "NEW-7". */
    public static function apiFlat(): string
    {
        return self::xlsx(['BSSE-4A' => [
            ['Day', 'Time', 'Course', 'Teacher', 'Room'],
            ['Monday', '08:30 - 10:00', 'SE201 Software Design', 'Dr. Ali', 'R-2'],
            ['Monday', '10:00 - 11:30', 'CS101 Programming Fundamentals', 'Ms. Sara', 'NEW-7'],
            ['Tuesday', 'bad', 'SE201 Software Design', 'Dr. Ali', 'R-2'],
        ]]);
    }
}
