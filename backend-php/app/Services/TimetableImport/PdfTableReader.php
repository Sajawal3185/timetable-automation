<?php

namespace App\Services\TimetableImport;

use App\Support\DayTime;
use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Rebuilds the timetable table on each page of a text PDF (one exported from
 * Excel or Word), so TimetableParser can read it like an Excel sheet.
 *
 * A PDF has no table structure, only words at x/y positions. So:
 *   1. words close together on the same baseline are joined into phrases, and
 *      phrases are grouped into lines by their y position;
 *   2. the header line is the first with 2+ time slots, 2+ day names, or the
 *      flat headers (Day + Time + Course);
 *   3. the header's phrases give the column centres; a phrase below belongs to
 *      the nearest centre;
 *   4. each row has a label on the left (the day, or the time when days run
 *      across the top; the time in a flat table). Every other line joins the
 *      nearest label, so "Course / Teacher / Room" on three lines stays one cell.
 * The section is taken from a title above the table ("BSSE-4A", "Section: 4A").
 *
 * Assumes one table per page. Scanned PDFs (pictures of text) can't be read:
 * they have no text at all, and the upload is refused with a clear message.
 */
final class PdfTableReader
{
    /** Phrases this close (in font sizes) on one baseline are one phrase. */
    private const JOIN_GAP = 0.9;

    /** @return list<Sheet> */
    public static function read(string $path): array
    {
        $config = new Config;
        $config->setDataTmFontInfoHasToBeIncluded(true);   // items become [matrix, text, font id, font size]
        $pdf = (new Parser([], $config))->parseFile($path);
        $sheets = [];
        $anyText = false;
        $days = null;   // NUML's later pages leave the day header out: page 1's columns are kept

        foreach ($pdf->getPages() as $i => $page) {
            $items = [];
            foreach ($page->getDataTm() as $item) {
                $text = SheetReader::clean((string) ($item[1] ?? ''));
                if ($text !== '') {
                    [$a, $b, $c, $d] = array_map('floatval', array_slice($item[0], 0, 4));
                    // The size on screen: the Tf size times the text matrix's scale (rotation-proof).
                    $size = (float) ($item[3] ?? 0) * sqrt($c * $c + $d * $d);
                    $scale = sqrt($a * $a + $b * $b) ?: 1.0;
                    $items[] = ['x' => (float) $item[0][4], 'y' => (float) $item[0][5], 'text' => $text, 'size' => $size,
                        'a' => $a / $scale, 'b' => $b / $scale];
                }
            }
            $anyText = $anyText || $items !== [];

            // NUML's own layout first (days across, sideways section names), then any table.
            $grid = PdfDayGridReader::read($items, $days, $i + 1);
            if ($grid !== null) {
                [$pageSheets, $days] = $grid;
                array_push($sheets, ...$pageSheets);

                continue;
            }
            $upright = array_values(array_filter($items, fn ($it) => abs($it['b'] ?? 0) < 0.1));
            $sheets[] = self::pageToSheet($upright, $i + 1);
        }

        if (! $anyText) {
            throw new RuntimeException('the PDF has no text in it (is it a scanned picture?). Upload the Excel file, or a PDF saved from Excel or Word');
        }

        return $sheets;
    }

    /**
     * One page's words -> a Sheet. Public for the tests, which feed positions directly.
     *
     * @param  list<array{x: float, y: float, text: string, size?: float}>  $items
     */
    public static function pageToSheet(array $items, int $pageNumber): Sheet
    {
        $name = "Page $pageNumber";
        if ($items === []) {
            return new Sheet($name, []);
        }

        $size = self::fontSize($items);
        $lines = self::lines($items, $size);

        $headerIndex = null;
        $kind = null;
        foreach (array_slice($lines, 0, 40, true) as $i => $line) {
            if ($kind = self::headerKind($line['phrases'])) {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            // No table found: hand over the lines as they are, so the parser reports it.
            return new Sheet($name, array_map(fn ($l) => array_column($l['phrases'], 'text'), $lines));
        }

        [$section, $issues] = self::sectionName(array_slice($lines, 0, $headerIndex), $name);
        $headerTexts = array_column($lines[$headerIndex]['phrases'], 'text');
        if ($kind === 'flat' && isset(TimetableParser::findFlatHeader([$headerTexts])[1]['section'])) {
            $issues = [];   // the table names the section on every row
        }

        // Column centres from the header.
        $header = $lines[$headerIndex]['phrases'];
        $columns = array_map(fn ($p) => self::centre($p, $size), $header);
        $titles = array_column($header, 'text');
        if ($kind !== 'flat') {
            // A grid's header has no label over the day (or time) column: add one on the left.
            $left = min(array_map(fn ($l) => $l['phrases'][0]['x'], array_slice($lines, $headerIndex + 1)) ?: [$header[0]['x']]);
            if ($left < $header[0]['x'] - $size) {
                array_unshift($columns, $left);
                array_unshift($titles, '');
            }
        }
        $labelColumn = $kind === 'flat' ? self::flatTimeColumn($titles) : 0;

        // Place every phrase below the header in a column.
        $body = [];
        foreach (array_slice($lines, $headerIndex + 1) as $line) {
            $cells = [];
            foreach ($line['phrases'] as $p) {
                $col = self::nearest($columns, self::centre($p, $size));
                $cells[$col] = isset($cells[$col]) ? $cells[$col].' '.$p['text'] : $p['text'];
            }
            $body[] = ['y' => $line['y'], 'cells' => $cells];
        }

        // Rows: one per label line; every other line joins the nearest label.
        $anchors = [];
        foreach ($body as $i => $line) {
            $label = $line['cells'][$labelColumn] ?? '';
            if (self::isLabel($label, $kind)) {
                $anchors[] = $i;
            }
        }
        if ($anchors === []) {
            return new Sheet($section, [$titles], $issues);
        }

        $rows = array_fill_keys($anchors, []);
        foreach ($body as $i => $line) {
            $best = $anchors[0];
            foreach ($anchors as $a) {
                if (abs($body[$a]['y'] - $line['y']) < abs($body[$best]['y'] - $line['y'])) {
                    $best = $a;
                }
            }
            $rows[$best][] = $line['cells'];
        }

        // A grid cell's lines are Course / Teacher / Room; a flat cell's lines are one wrapped value.
        $joiner = $kind === 'flat' ? ' ' : "\n";
        $cells = [$titles];
        foreach ($rows as $lineCells) {
            $row = array_fill(0, count($columns), '');
            foreach ($lineCells as $lc) {
                foreach ($lc as $col => $text) {
                    $row[$col] = $row[$col] === '' ? $text : $row[$col].$joiner.$text;
                }
            }
            $cells[] = $row;
        }

        return new Sheet($section, $cells, $issues);
    }

    /**
     * The usual font size on the page: the PDF's own sizes when it gives them,
     * else the usual line spacing / 1.2. Between 5 and 14 points.
     */
    private static function fontSize(array $items): float
    {
        $sizes = array_values(array_filter(array_map(fn ($i) => $i['size'] ?? 0, $items), fn ($s) => $s > 0));
        if ($sizes !== []) {
            sort($sizes);

            return max(5.0, min(14.0, $sizes[intdiv(count($sizes), 2)]));
        }

        $ys = array_values(array_unique(array_map(fn ($i) => round($i['y']), $items)));
        rsort($ys);
        $gaps = [];
        for ($i = 1; $i < count($ys); $i++) {
            if ($ys[$i - 1] - $ys[$i] >= 3) {
                $gaps[] = $ys[$i - 1] - $ys[$i];
            }
        }
        if ($gaps === []) {
            return 9.0;
        }
        sort($gaps);

        return max(5.0, min(14.0, $gaps[intdiv(count($gaps), 2)] / 1.2));
    }

    /**
     * Words -> lines (top of the page first), each with its phrases left to right.
     *
     * @return list<array{y: float, phrases: list<array{x: float, y: float, text: string}>}>
     */
    private static function lines(array $items, float $size): array
    {
        usort($items, fn ($a, $b) => [$b['y'], $a['x']] <=> [$a['y'], $b['x']]);

        $lines = [];
        foreach ($items as $item) {
            $last = array_key_last($lines);
            if ($last !== null && abs($lines[$last]['y'] - $item['y']) <= $size * 0.35) {
                $lines[$last]['items'][] = $item;
            } else {
                $lines[] = ['y' => $item['y'], 'items' => [$item]];
            }
        }

        foreach ($lines as &$line) {
            usort($line['items'], fn ($a, $b) => $a['x'] <=> $b['x']);
            $phrases = [];
            foreach ($line['items'] as $item) {
                $prev = array_key_last($phrases);
                if ($prev !== null && $item['x'] - self::rightEdge($phrases[$prev], $size) < $size * self::JOIN_GAP) {
                    $phrases[$prev]['text'] .= ' '.$item['text'];
                } else {
                    $phrases[] = $item;
                }
            }
            $line = ['y' => $line['y'], 'phrases' => $phrases];
        }

        return $lines;
    }

    /** Where a phrase probably ends: PDFs don't store widths, so about half a font size per letter. */
    private static function rightEdge(array $phrase, float $size): float
    {
        return $phrase['x'] + mb_strlen($phrase['text']) * $size * 0.5;
    }

    private static function centre(array $phrase, float $size): float
    {
        return ($phrase['x'] + self::rightEdge($phrase, $size)) / 2;
    }

    private static function nearest(array $columns, float $x): int
    {
        $best = 0;
        foreach ($columns as $i => $c) {
            if (abs($c - $x) < abs($columns[$best] - $x)) {
                $best = $i;
            }
        }

        return $best;
    }

    /** "times" (slots across the top), "days" (days across the top), "flat", or null. */
    private static function headerKind(array $phrases): ?string
    {
        $texts = array_column($phrases, 'text');
        if (count(array_filter($texts, fn ($t) => DayTime::parseTimeRange($t) !== null)) >= 2) {
            return 'times';
        }
        if (count(array_filter($texts, fn ($t) => DayTime::normalizeDay($t) !== null && mb_strlen($t) <= 10)) >= 2) {
            return 'days';
        }

        return TimetableParser::findFlatHeader([$texts]) !== null ? 'flat' : null;
    }

    private static function flatTimeColumn(array $titles): int
    {
        [, $cols] = TimetableParser::findFlatHeader([$titles]);

        return $cols['time'] ?? $cols['start_time'];
    }

    private static function isLabel(string $text, string $kind): bool
    {
        return match ($kind) {
            'times' => DayTime::normalizeDay($text) !== null,
            'days' => DayTime::parseTimeRange($text) !== null,
            'flat' => DayTime::parseTime($text) !== null,
        };
    }

    /**
     * The section from the lines above the table: "Section: BSSE 4A" or a line
     * that is only a class name like "BSSE-4A". Else the page name, with a warning.
     *
     * @return array{0: string, 1: list<ImportIssue>}
     */
    private static function sectionName(array $linesAbove, string $pageName): array
    {
        $texts = array_map(fn ($l) => implode(' ', array_column($l['phrases'], 'text')), $linesAbove);

        foreach ($texts as $t) {
            if (preg_match('/\b(?:section|class|batch)\s*[:\-]\s*(\S.{0,48})$/i', $t, $m)) {
                return [trim($m[1]), []];
            }
        }
        foreach ($texts as $t) {
            if (preg_match('/^\s*([A-Z]{2,6}[\s\-]?\d{1,2}\s?-?[A-Z]?)\s*$/i', $t, $m)) {
                return [trim($m[1]), []];
            }
        }

        return [$pageName, [new ImportIssue($pageName, 0,
            "No section name found above the table; the classes were put in section '$pageName'. Put a title like 'Section: BSSE-4A' on each page, or rename the section afterwards",
            'warning')]];
    }
}
