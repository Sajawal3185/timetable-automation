<?php

namespace App\Services\TimetableImport;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Reads an uploaded file into tables of strings (read_workbook in Python):
 *   .xlsx / .xlsm / .xls  one table per sheet, merged cells filled in
 *   .csv                  one table, named after the file ("BSCS-3A.csv" -> "BSCS-3A")
 *   .pdf                  one table per page (PdfTableReader)
 */
final class SheetReader
{
    public const EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'csv', 'pdf'];

    /**
     * @param  string  $path  the file on disk (an upload's temp file)
     * @param  string  $originalName  the name it was uploaded with: gives the type, and the CSV's sheet name
     * @return list<Sheet>
     */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($ext) {
            'csv' => [self::readCsv($path, pathinfo($originalName, PATHINFO_FILENAME))],
            'xlsx', 'xlsm', 'xls' => self::readSpreadsheet($path, $ext === 'xls' ? 'Xls' : 'Xlsx'),
            'pdf' => PdfTableReader::read($path),
            default => throw new InvalidArgumentException("Unsupported file type '.$ext'. Upload .xlsx, .csv or .pdf"),
        };
    }

    /** Any cell value -> a clean string; spaces and tabs squeezed, newlines kept (cell_str). */
    public static function clean(string $text): string
    {
        // \x0B, not \v: in PCRE \v means every vertical space, newlines included
        // (in Python it is only the vertical tab), and a cell's lines must stay apart.
        return trim(preg_replace('/[ \t\r\f\x0B]+/', ' ', $text));
    }

    private static function readCsv(string $path, string $name): Sheet
    {
        $fh = fopen($path, 'r');
        $cells = [];
        try {
            while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
                if ($cells === [] && isset($row[0])) {
                    $row[0] = preg_replace('/^\x{FEFF}/u', '', $row[0]);   // Excel's UTF-8 BOM
                }
                $cells[] = array_map(fn ($v) => self::clean((string) $v), $row);
            }
        } finally {
            fclose($fh);
        }

        return new Sheet($name, $cells);
    }

    /** @return list<Sheet> */
    private static function readSpreadsheet(string $path, string $type): array
    {
        $reader = IOFactory::createReader($type);
        $reader->setReadDataOnly(false);   // keeps number formats, so a time cell is known to be a time
        $book = $reader->load($path);

        $sheets = [];
        foreach ($book->getWorksheetIterator() as $ws) {
            $maxRow = $ws->getHighestDataRow();
            $maxCol = Coordinate::columnIndexFromString($ws->getHighestDataColumn());
            $cells = [];
            for ($r = 1; $r <= $maxRow; $r++) {
                $row = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $row[] = self::cellText($ws->getCell([$c, $r]));
                }
                $cells[] = $row;
            }

            // A merged cell keeps its value only in the top-left cell: copy it into the rest.
            foreach ($ws->getMergeCells() as $range) {
                [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);
                $top = $cells[$r1 - 1][$c1 - 1] ?? '';
                for ($r = $r1; $r <= min($r2, $maxRow); $r++) {
                    for ($c = $c1; $c <= min($c2, $maxCol); $c++) {
                        $cells[$r - 1][$c - 1] = $top;
                    }
                }
            }

            $sheets[] = new Sheet($ws->getTitle(), $cells);
        }

        return $sheets;
    }

    private static function cellText(Cell $cell): string
    {
        try {
            $value = $cell->getCalculatedValue();
        } catch (Throwable) {
            $value = $cell->getOldCalculatedValue() ?? $cell->getValue();   // a formula PhpSpreadsheet can't run
        }

        if ($value === null) {
            return '';
        }
        if (is_numeric($value) && ! is_string($value) && Date::isDateTime($cell)) {
            $dt = Date::excelToDateTimeObject((float) $value);

            // A time on its own is stored as a date in 1899/1900, like openpyxl's time values.
            return (int) $dt->format('Y') <= 1900 ? $dt->format('H:i') : $dt->format('Y-m-d H:i');
        }
        if (is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        return self::clean((string) $value);
    }
}
