<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** php artisan migrate:deploy, what the container runs on start (routes/console.php). */
class DeployMigrateTest extends TestCase
{
    public function test_it_migrates_and_can_run_again(): void
    {
        // On MySQL (CI) this also takes and releases the GET_LOCK.
        $this->artisan('migrate:deploy')->assertExitCode(0);
        $this->artisan('migrate:deploy')->assertExitCode(0);

        if (DB::getDriverName() === 'mysql') {
            $this->assertSame(1, (int) DB::scalar("SELECT IS_FREE_LOCK('timetable_php_migrate')"));
        }
    }

    public function test_it_refuses_fastapis_database(): void
    {
        // Pretend alembic_version exists (creating it for real would commit
        // RefreshDatabase's transaction on MySQL).
        Schema::shouldReceive('hasTable')->once()->with('alembic_version')->andReturn(true);

        $this->artisan('migrate:deploy')
            ->expectsOutputToContain("This is FastAPI's database")
            ->assertExitCode(1);
    }
}
