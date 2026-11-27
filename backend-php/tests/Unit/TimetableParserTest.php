<?php

namespace Tests\Unit;

use App\Services\TimetableImport\ParseResult;
use App\Services\TimetableImport\PdfTableReader;
use App\Services\TimetableImport\SheetReader;
use App\Services\TimetableImport\TimetableParser;
use App\Services\TimetableImport\TimetableRow;
use App\Support\DayTime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TinyPdf;
use Tests\Support\Workbooks;

/** Port of backend/tests/test_timetable_import.py (parsing and validation), plus the PDF reader. */
class TimetableParserTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempFiles, 'file_exists'));
        parent::tearDown();
    }

    // ------------------------------------------------------------ small helpers

    #[DataProvider('ranges')]
    public function test_parse_time_range(string $text, ?array $expected): void
    {
        $this->assertSame($expected, DayTime::parseTimeRange($text));
    }

    public static function ranges(): array
    {
        return [
            ['08:30 - 10:00', ['08:30:00', '10:00:00']], ['8:30-10:00', ['08:30:00', '10:00:00']],
            ['8:30 to 10:00', ['08:30:00', '10:00:00']], ["11:30 \u{2013} 1:00 PM", ['11:30:00', '13:00:00']],
            ['1:00-2:30', ['13:00:00', '14:30:00']],
            ['', null], ['8:30', null], ['Course', null], ['x - y', null],
        ];
    }

    public function test_split_course(): void
    {
        $this->assertSame(['CS-101', 'Programming Fundamentals'], TimetableParser::splitCourse('CS-101 Programming Fundamentals'));
        $this->assertSame(['SE201', 'Software Design'], TimetableParser::splitCourse('SE201 - Software Design'));
        $this->assertSame(['', 'Islamic Studies'], TimetableParser::splitCourse('Islamic Studies'));
    }

    public function test_parse_cell(): void
    {
        $this->assertSame(['CS101', 'Programming', 'Dr. Ali', 'Room 5'], TimetableParser::parseCell("CS101 Programming\nDr. Ali\nRoom 5"));
        $this->assertSame(['', 'Calculus', 'Ms. Sara', 'LT-2'], TimetableParser::parseCell('Calculus | Ms. Sara | LT-2'));
        $this->assertSame('Lab 3', TimetableParser::parseCell("Physics\nLab 3")[3]);
        $this->assertSame('Dr. Khan', TimetableParser::parseCell("Physics\nDr. Khan")[2]);
        foreach (['', '-', 'Break', 'LUNCH', 'free'] as $empty) {
            $this->assertNull(TimetableParser::parseCell($empty), $empty);
        }
    }

    // ------------------------------------------------------------ Excel and CSV files

    public function test_flat_sheet(): void
    {
        $result = $this->parseFile(Workbooks::flat(), 'se.xlsx', 'SE');

        $this->assertSame(['BSSE-4A'], $result->sheetsRead);
        $this->assertSame(['Instructions'], $result->sheetsSkipped);
        $this->assertCount(3, $result->rows);
        [$first, $second, $third] = $result->rows;
        $this->assertSame(['BSSE-4A', 'Monday', '08:30:00', '10:00:00'], [$first->section, $first->day, $first->startTime, $first->endTime]);
        $this->assertSame(['SE201', 'Software Design', 'Dr. Ali', 'R-2'], [$first->courseCode, $first->courseTitle, $first->teacher, $first->room]);
        $this->assertSame('Monday', $second->day);   // blank day cell inherited
        $this->assertSame('Tuesday', $third->day);
        $messages = implode(' | ', array_map(fn ($i) => $i->message, $result->issues));
        $this->assertStringContainsString('time', $messages);
        $this->assertStringContainsString("Unrecognised day 'Funday'", $messages);
        $this->assertSame(['error', 'error'], array_map(fn ($i) => $i->level, $result->issues));
    }

    public function test_grid_sheet(): void
    {
        $rows = $this->parseFile(Workbooks::grid(), 'ai.xlsx', 'AI')->rows;

        $this->assertCount(3, $rows);   // the "Break" cell and the empty cells are skipped
        $monFirst = $this->find($rows, fn ($r) => $r->day === 'Monday' && $r->startTime === '08:30:00');
        $this->assertSame(['AI101', 'Intro to AI', 'Dr. Noor', 'R-1', 'BSAI-2B'],
            [$monFirst->courseCode, $monFirst->courseTitle, $monFirst->teacher, $monFirst->room, $monFirst->section]);
        $math = $this->find($rows, fn ($r) => $r->courseTitle === 'Math');
        $this->assertSame(['11:30:00', '13:00:00'], [$math->startTime, $math->endTime]);
    }

    public function test_grid_sheet_transposed_gives_same_result(): void
    {
        $this->assertSame(
            $this->keys($this->parseFile(Workbooks::grid(), 'a.xlsx', 'CS')->rows),
            $this->keys($this->parseFile(Workbooks::grid(transposed: true), 'b.xlsx', 'CS')->rows),
        );
    }

    public function test_merged_day_cells_in_grid(): void
    {
        $bytes = Workbooks::xlsx(['S1' => [
            ['Day', 'Slot', '08:30 - 10:00', '10:00 - 11:30'],
            ['Monday', 'A', "Maths\nDr. X\nR-1", ''],
            ['', 'B', '', "Physics\nDr. Y\nR-2"],
        ]], ['S1' => ['A2:A3']]);

        $found = array_map(fn ($r) => [$r->day, $r->courseTitle], $this->parseFile($bytes, 'm.xlsx', 'IT')->rows);
        $this->assertEqualsCanonicalizing([['Monday', 'Maths'], ['Monday', 'Physics']], $found);
    }

    public function test_excel_time_cells_are_read_as_times(): void
    {
        // Typing 9:00 into Excel stores 0.375 with a time format; the reader goes by the format.
        $bytes = Workbooks::xlsx(['BSCS-1A' => [
            ['Day', 'Start', 'End', 'Course'],
            ['Monday', 0.375, '=TIME(10,30,0)', 'CS101 Programming'],
        ]], formats: ['BSCS-1A' => ['B2:C2' => 'h:mm']]);

        $row = $this->parseFile($bytes, 't.xlsx', 'CS')->rows[0];
        $this->assertSame(['09:00:00', '10:30:00'], [$row->startTime, $row->endTime]);
    }

    public function test_csv_flat(): void
    {
        $csv = "\u{FEFF}Day,Start,End,Course Code,Course,Teacher,Room\nMon,09:00,10:30,CS301,Algorithms,Dr. Q,R-1\n";

        $rows = $this->parseFile($csv, 'BSCS-3A.csv', 'CS')->rows;
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertSame(['BSCS-3A', 'Monday', '09:00:00', '10:30:00', 'CS301', 'Algorithms'],
            [$r->section, $r->day, $r->startTime, $r->endTime, $r->courseCode, $r->courseTitle]);
    }

    public function test_unrecognised_sheet_is_reported_not_crashed(): void
    {
        $result = $this->parseFile(Workbooks::xlsx(['Weird' => [['hello', 'world'], ['foo', 'bar']]]), 'w.xlsx', 'SE');

        $this->assertSame([], $result->rows);
        $this->assertStringContainsString('not recognised', $result->issues[0]->message);
        $this->assertSame(['Weird'], $result->sheetsSkipped);
    }

    public function test_unknown_file_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SheetReader::read($this->temp('a'), 'x.txt');
    }

    // ------------------------------------------------------------ PDF files

    public function test_pdf_grid_gives_the_same_classes_as_the_excel_grid(): void
    {
        $page = TinyPdf::gridPage('Section: BSAI-2B', ['08:30 - 10:00', '10:00 - 11:30', '11:30 - 1:00'], [
            'Monday' => [0 => 'AI101 Intro to AI|Dr. Noor|R-1', 1 => 'Break', 2 => 'Math|Ms. Zara|R-2'],
            'Tuesday' => [1 => 'AI101 Intro to AI|Dr. Noor|R-1'],
        ]);

        $pdf = $this->parseFile(TinyPdf::make([$page]), 'ai.pdf', 'AI');
        $excel = $this->parseFile(Workbooks::grid(), 'ai.xlsx', 'AI');

        $this->assertSame([], $pdf->issues);
        $this->assertSame(['BSAI-2B'], $pdf->sheetsRead);
        $this->assertSame($this->keys($excel->rows), $this->keys($pdf->rows));
        $this->assertSame(['BSAI-2B'], array_values(array_unique(array_map(fn ($r) => $r->section, $pdf->rows))));
    }

    public function test_pdf_with_days_across_the_top(): void
    {
        // Times down the left, days across, two lines per class (course / room).
        $texts = [[40, 560, 'BSSE-3B'], [120, 520, 'Monday'], [260, 520, 'Tuesday']];
        foreach ([['08:30 - 10:00', 500], ['10:00 - 11:30', 460]] as [$slot, $y]) {
            $texts[] = [40, $y - 5, $slot];
        }
        array_push($texts, [120, 500, 'SE210 Databases'], [120, 490, 'Lab 2'], [260, 460, 'SE220 Networks'], [260, 450, 'Dr. Hina']);

        $rows = $this->parseFile(TinyPdf::make([$texts]), 'se.pdf', 'SE')->rows;

        $this->assertCount(2, $rows);
        $db = $this->find($rows, fn ($r) => $r->courseCode === 'SE210');
        $this->assertSame(['BSSE-3B', 'Monday', '08:30:00', 'Databases', '', 'Lab 2'],
            [$db->section, $db->day, $db->startTime, $db->courseTitle, $db->teacher, $db->room]);
        $net = $this->find($rows, fn ($r) => $r->courseCode === 'SE220');
        $this->assertSame(['Tuesday', '10:00:00', 'Dr. Hina'], [$net->day, $net->startTime, $net->teacher]);
    }

    public function test_pdf_flat_table_with_a_section_column_and_two_pages(): void
    {
        $header = [[40, 520, 'Section'], [110, 520, 'Day'], [180, 520, 'Time'], [290, 520, 'Course'], [470, 520, 'Teacher'], [590, 520, 'Room']];
        $page1 = [...$header,
            [40, 500, 'BSCS-1A'], [110, 500, 'Monday'], [180, 500, '08:30 - 10:00'], [290, 500, 'CS101 Programming'], [470, 500, 'Dr. Q'], [590, 500, 'R-1'],
            [290, 490, 'Fundamentals'],   // the course title wraps onto a second line
            [40, 470, 'BSCS-1A'], [110, 470, 'Tuesday'], [180, 470, '10:00 - 11:30'], [290, 470, 'MT101 Calculus'], [470, 470, 'Ms. R'], [590, 470, 'R-2'],
        ];
        $page2 = [...$header,
            [40, 500, 'BSCS-2A'], [110, 500, 'Friday'], [180, 500, '2:00 - 3:30 PM'], [290, 500, 'CS201 Data Structures'], [470, 500, 'Dr. Q'], [590, 500, 'Lab 1'],
        ];

        $result = $this->parseFile(TinyPdf::make([$page1, $page2]), 'cs.pdf', 'CS');

        $this->assertSame([], $result->issues);
        $this->assertSame(['Page 1', 'Page 2'], $result->sheetsRead);
        $this->assertCount(3, $result->rows);
        [$a, $b, $c] = $result->rows;
        $this->assertSame(['BSCS-1A', 'Monday', 'CS101', 'Programming Fundamentals', 'Dr. Q', 'R-1'],
            [$a->section, $a->day, $a->courseCode, $a->courseTitle, $a->teacher, $a->room]);
        $this->assertSame(['Tuesday', '10:00:00', '11:30:00'], [$b->day, $b->startTime, $b->endTime]);
        $this->assertSame(['BSCS-2A', 'Friday', '14:00:00', '15:30:00', 'Lab 1'], [$c->section, $c->day, $c->startTime, $c->endTime, $c->room]);
    }

    /**
     * NUML's own PDF layout (F26 SE and BSCS timetables): days across, a time
     * label per row, cells Course / Room / Teacher, the section written sideways,
     * two sections per page, and later pages without the day header.
     */
    public function test_numl_department_pdf(): void
    {
        $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY'];
        $page1 = [[90, 560, 'BS SOFTWARE ENGINEERING - Fall 2026 (Morning)'], [35, 534, 'Semester'], [94, 534, 'Slot Time']];
        foreach ($days as $k => $day) {
            $page1[] = [190 + 130 * $k, 532, $day];
        }
        $block = function (float $top, string $code, string $group, array $rows) {
            $texts = [[60, $top - 120, $code, 90], [77, $top - 110, $group, 90]];   // sideways section name
            foreach ($rows as $r => [$slot, $cells]) {
                $y = $top - 40 * $r;
                $texts[] = [102, $y, $slot];
                foreach ($cells as $k => $cell) {
                    if ($cell === 'FREE SLOT') {
                        $texts[] = [200 + 130 * $k, $y, 'FREE SLOT', 45];   // the diagonal stamp

                        continue;
                    }
                    foreach (explode('|', $cell) as $line => $text) {
                        $texts[] = [190 + 130 * $k, $y + 13 - 13 * $line, $text];
                    }
                }
            }

            return $texts;
        };
        $page1 = [...$page1,
            ...$block(492, 'BSSE - B39', 'I-A-Mor', [
                ['08:00 - 08:50', ['Prog. Fundamentals|Room # 8 - Ghazali|Mr. Khateeb Khan', 'FREE SLOT', 'Applied Physics Lab|Control Lab - Ghazali Block|Mr. Favvad Qadir']],
                ['08:55 - 09:45', ['Ideology & Const. of|Pakistan|Room # 8 - Ghazali|Ms. Hafsa', 'FYP', 'Maths-1 for Medical Students']],
            ]),
            ...$block(292, 'BSSE - B39', 'I-B-Mor', [
                ['08:00 - 08:50', ['DLD Lab|Signal Process & Comm. Lab - Ghazali|Block|Mr. Farooq', 'Pre-Math I (Thursday)|Room # 06 - Ghazali|(1:45 - 4:30)|Dr Afshan Qayum', '']],
            ]),
        ];
        $page2 = $block(492, 'BSSE - B38', 'II-A-Mor', [
            ['08:00 - 08:50', ['', 'Discrete Structures|Room # 43 - Ghazali|Dr. Safi Ullah Nasir', '']],
            ['08:55 - 09:45', ['', 'Discrete Structures|Room # 43 - Ghazali|Dr. Safi Ullah Nasir', '']],
        ]);

        $result = $this->parseFile(TinyPdf::make([$page1, $page2], 6.6), 'se.pdf', 'SE');

        $this->assertSame([], $result->issues);
        $this->assertSame(['BSSE - B39 I-A-Mor', 'BSSE - B39 I-B-Mor', 'BSSE - B38 II-A-Mor'], $result->sheetsRead);
        $got = array_map(fn ($r) => [$r->section, $r->day, substr($r->startTime, 0, 5), $r->courseTitle, $r->room, $r->teacher], $result->rows);
        $this->assertEqualsCanonicalizing([
            ['BSSE - B39 I-A-Mor', 'Monday', '08:00', 'Prog. Fundamentals', 'Room # 8 - Ghazali', 'Mr. Khateeb Khan'],
            ['BSSE - B39 I-A-Mor', 'Wednesday', '08:00', 'Applied Physics Lab', 'Control Lab - Ghazali Block', 'Mr. Favvad Qadir'],
            ['BSSE - B39 I-A-Mor', 'Monday', '08:55', 'Ideology & Const. of Pakistan', 'Room # 8 - Ghazali', 'Ms. Hafsa'],
            ['BSSE - B39 I-A-Mor', 'Wednesday', '08:55', 'Maths-1 for Medical Students', '', ''],
            ['BSSE - B39 I-B-Mor', 'Monday', '08:00', 'DLD Lab', 'Signal Process & Comm. Lab - Ghazali Block', 'Mr. Farooq'],
            ['BSSE - B38 II-A-Mor', 'Tuesday', '08:00', 'Discrete Structures', 'Room # 43 - Ghazali', 'Dr. Safi Ullah Nasir'],
            ['BSSE - B38 II-A-Mor', 'Tuesday', '08:55', 'Discrete Structures', 'Room # 43 - Ghazali', 'Dr. Safi Ullah Nasir'],
        ], $got);
    }

    public function test_pdf_page_without_a_section_title_warns(): void
    {
        $page = TinyPdf::gridPage('Timetable Fall 2026', ['08:30 - 10:00', '10:00 - 11:30'], ['Monday' => [0 => 'Maths|Dr. X|R-1']]);

        $result = $this->parseFile(TinyPdf::make([$page]), 'x.pdf', 'SE');

        $this->assertSame('Page 1', $result->rows[0]->section);
        $this->assertSame('warning', $result->issues[0]->level);
        $this->assertStringContainsString('No section name', $result->issues[0]->message);
    }

    public function test_pdf_without_text_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('scanned');
        SheetReader::read($this->temp(TinyPdf::make([[]])), 'scan.pdf');
    }

    public function test_pdf_page_without_a_table_is_reported(): void
    {
        $sheet = PdfTableReader::pageToSheet([['x' => 40, 'y' => 500, 'text' => 'Notes'], ['x' => 40, 'y' => 480, 'text' => 'nothing here']], 1);

        $result = TimetableParser::parse([$sheet], 'SE');
        $this->assertSame(['Page 1'], $result->sheetsSkipped);
        $this->assertStringContainsString('not recognised', $result->issues[0]->message);
    }

    // ------------------------------------------------------------ validation

    public function test_validate_drops_bad_times_and_exact_duplicates(): void
    {
        $rows = [$this->row(), $this->row(), $this->row(endTime: '09:00:00'), $this->row(startTime: '11:00:00', endTime: '10:00:00'),
            $this->row(day: 'Tuesday')];

        [$good, $issues] = TimetableParser::validate($rows);
        $this->assertCount(2, $good);
        $levels = array_map(fn ($i) => $i->level, $issues);
        sort($levels);
        $this->assertSame(['error', 'error', 'warning'], $levels);
    }

    public function test_validate_refuses_values_too_long_for_the_database(): void
    {
        [$good, $issues] = TimetableParser::validate([$this->row(room: str_repeat('R', 51)), $this->row(courseCode: str_repeat('C', 21))]);

        $this->assertSame([], $good);
        $this->assertSame(['Too long: room (max 50 characters)', 'Too long: course code (max 20 characters)'],
            array_map(fn ($i) => $i->message, $issues));
    }

    // ------------------------------------------------------------ helpers

    private function row(...$changes): TimetableRow
    {
        return new TimetableRow(...[
            'department' => 'SE', 'section' => 'A', 'day' => 'Monday', 'startTime' => '09:00:00', 'endTime' => '10:00:00',
            'courseTitle' => 'Maths', 'courseCode' => 'M1', 'sourceSheet' => 'A', 'sourceRow' => 2, ...$changes,
        ]);
    }

    private function parseFile(string $bytes, string $name, string $department): ParseResult
    {
        return TimetableParser::parse(SheetReader::read($this->temp($bytes), $name), $department);
    }

    private function temp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tt');
        file_put_contents($path, $bytes);

        return $this->tempFiles[] = $path;
    }

    private function find(array $rows, callable $match): TimetableRow
    {
        foreach ($rows as $row) {
            if ($match($row)) {
                return $row;
            }
        }
        $this->fail('no such row');
    }

    /** What the transposed and PDF tests compare: the classes, sorted. */
    private function keys(array $rows): array
    {
        $keys = array_map(fn ($r) => [$r->day, $r->startTime, $r->endTime, $r->courseCode, $r->courseTitle, $r->teacher, $r->room], $rows);
        sort($keys);

        return $keys;
    }
}
