<?php

namespace Tests\Unit;

use App\Support\DayTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** normalize_day / parse_time from timetable_import.py give the same answers. */
class DayTimeTest extends TestCase
{
    #[DataProvider('days')]
    public function test_normalize_day(?string $text, ?string $expected): void
    {
        $this->assertSame($expected, DayTime::normalizeDay($text));
    }

    public static function days(): array
    {
        return [
            ['Monday', 'Monday'], ['mon', 'Monday'], ['TUE', 'Tuesday'], ['Wed.', 'Wednesday'],
            ['thursday ', 'Thursday'], ['Funday', null], ['mo', null], ['', null], [null, null],
        ];
    }

    #[DataProvider('times')]
    public function test_parse_time(string $text, ?string $expected): void
    {
        $this->assertSame($expected, DayTime::parseTime($text));
    }

    public static function times(): array
    {
        return [
            ['10:00', '10:00:00'], ['8:30', '08:30:00'], ['08.30', '08:30:00'], ['8:30 AM', '08:30:00'],
            ['2 pm', '14:00:00'], ['1:00', '13:00:00'],        // no am/pm and 1-7: afternoon
            ['12 am', '00:00:00'], ['12:15 p.m.', '12:15:00'], ['10:00:00', '10:00:00'],
            ['xx', null], ['25:00', null], ['9:75', null],
        ];
    }

    public function test_repr_matches_python(): void
    {
        $this->assertSame("'Funday'", DayTime::repr('Funday'));
        $this->assertSame('"it\'s"', DayTime::repr("it's"));
    }
}
