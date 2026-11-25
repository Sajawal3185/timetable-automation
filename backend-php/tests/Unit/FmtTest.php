<?php

namespace Tests\Unit;

use App\Support\Fmt;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** Dates and times come out exactly as FastAPI/Pydantic writes them. */
class FmtTest extends TestCase
{
    public function test_date(): void
    {
        $this->assertSame('2026-11-21', Fmt::date('2026-11-21'));
        $this->assertSame('2026-11-21', Fmt::date(Carbon::parse('2026-11-21 13:45:00')));
        $this->assertNull(Fmt::date(null));
    }

    public function test_time_has_seconds_like_pydantic(): void
    {
        $this->assertSame('09:00:00', Fmt::time('09:00:00'));
        $this->assertSame('09:00:00', Fmt::time('9:00'));
        $this->assertSame('14:30:00', Fmt::time(Carbon::parse('2026-11-21 14:30')));
        $this->assertNull(Fmt::time(null));
    }

    public function test_datetime_is_iso_without_timezone(): void
    {
        $this->assertSame('2026-11-21T09:05:00', Fmt::dateTime('2026-11-21 09:05:00'));
        $this->assertSame('2026-11-21T09:05:00.250000', Fmt::dateTime('2026-11-21 09:05:00.25'));
        $this->assertNull(Fmt::dateTime(null));
    }
}
