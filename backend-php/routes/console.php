<?php

use App\Services\DemoSeed;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| What the container runs on every start (docker/start.sh), instead of a bare
| "migrate --force":
|
| 1. Refuses to touch FastAPI's database. That one has Alembic's
|    alembic_version table; Laravel's tables must never be created next to it.
| 2. On MySQL, holds a database lock while migrating, so two containers that
|    start at the same moment (more than one instance) take turns instead of
|    running the same migration twice. The second one then has nothing to do.
*/
Artisan::command('migrate:deploy', function () {
    if (Schema::hasTable('alembic_version')) {
        $this->error("This is FastAPI's database (it has alembic_version). Point DB_URL at timetable_php instead.");

        return 1;
    }

    $mysql = DB::getDriverName() === 'mysql';
    $lock = 'timetable_php_migrate';
    if ($mysql && (int) DB::scalar('SELECT GET_LOCK(?, 600)', [$lock]) !== 1) {
        $this->error('Another container held the migration lock for 10 minutes; giving up.');

        return 1;
    }

    try {
        return $this->call('migrate', ['--force' => true]);
    } finally {
        if ($mysql) {
            DB::scalar('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
})->purpose('Migrate on deploy: never FastAPI\'s database, one container at a time');

/*
| The demo data (port of backend/scripts/seed.py). From backend-php/:
|
|   php artisan demo:seed            first load; safe to repeat
|   php artisan demo:seed --reset    before every demo run: DELETES every swap request,
|                                    makeup class and notification, reloads the demo
|                                    timetable, resets the demo passwords
|
| Every activated demo account gets SEED_PASSWORD (.env, 8+ characters).
*/
Artisan::command('demo:seed {--reset : delete ALL swaps, makeups and notifications and reload the demo timetable}', function () {
    if (Schema::hasTable('alembic_version')) {
        $this->error("This is FastAPI's database (it has alembic_version). Point DB_URL at timetable_php instead.");

        return 1;
    }
    $password = (string) config('timetable.seed_password');
    if (mb_strlen($password) < DemoSeed::MIN_PASSWORD) {
        $this->error('Set SEED_PASSWORD ('.DemoSeed::MIN_PASSWORD.'+ characters): it becomes the password of every demo account.');

        return 2;
    }

    $done = DemoSeed::run($password, (bool) $this->option('reset'));

    $this->info("Seed finished: {$done['added']} rows added, {$done['timetable_entries']} timetable entries loaded.");
    if ($this->option('reset')) {
        $this->info("Reset removed {$done['swaps_deleted']} swap requests, {$done['makeups_deleted']} makeup classes "
            ."and {$done['notifications_deleted']} notifications.");
    }
    $this->line('Accounts (password = SEED_PASSWORD, except the last one, which is not activated):');
    foreach (DemoSeed::USERS as [$identifier, $name, $role]) {
        $this->line(sprintf('  %-8s %-12s %s', $role, $identifier, $name));
    }

    return 0;
})->purpose('Load the demo data (--reset: back to the starting state)');
