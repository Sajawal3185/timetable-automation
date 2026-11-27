<?php

namespace Tests\Support;

/**
 * Writes a real, minimal PDF with text at exact positions (in points, from the
 * bottom-left corner), like the PDFs Excel and Word export. For the import tests.
 *
 *   TinyPdf::make([[[40, 500, 'Monday'], [120, 500, 'AI101 Intro to AI']]]);   // one page
 */
final class TinyPdf
{
    /** @param list<list<array{0: float, 1: float, 2: string}>> $pages */
    public static function make(array $pages, float $fontSize = 9): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $kids = [];
        $next = 4;
        foreach ($pages as $texts) {
            [$pageId, $contentId] = [$next++, $next++];
            $kids[] = "$pageId 0 R";
            $stream = '';
            foreach ($texts as [$x, $y, $text]) {
                $escaped = strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
                $stream .= sprintf("BT /F1 %.1F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $fontSize, $x, $y, $escaped);
            }
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents $contentId 0 R >>";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /**
     * A grid page: title, slots across the top, days down the left, each class
     * on three lines (course / teacher / room) with the day on the middle line.
     *
     * @param  array<string, array<int, string>>  $days  day => [slot index => "Course|Teacher|Room"]
     * @return list<array{0: float, 1: float, 2: string}>
     */
    public static function gridPage(string $title, array $slots, array $days): array
    {
        $texts = [[40, 560, $title]];
        foreach ($slots as $i => $slot) {
            $texts[] = [120 + 140 * $i, 520, $slot];
        }
        $y = 500;
        foreach ($days as $day => $cells) {
            $texts[] = [40, $y - 10, $day];
            foreach ($cells as $i => $cell) {
                foreach (explode('|', $cell) as $line => $text) {
                    $texts[] = [120 + 140 * $i, $y - 10 * $line, $text];
                }
            }
            $y -= 40;
        }

        return $texts;
    }
}
