<?php

namespace Database\Seeders;

use App\Services\DemoSeed;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * `php artisan db:seed` / `migrate:fresh --seed`: the demo data, without reset
 * (safe to repeat). The logic and the list of accounts are in App\Services\DemoSeed;
 * `php artisan demo:seed --reset` also puts a used demo back to its starting state.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) config('timetable.seed_password');
        if (mb_strlen($password) < DemoSeed::MIN_PASSWORD) {
            throw new RuntimeException('Set SEED_PASSWORD ('.DemoSeed::MIN_PASSWORD.'+ characters): it becomes the password of every demo account.');
        }

        $done = DemoSeed::run($password);
        $this->command?->info("Demo data: {$done['added']} rows added, {$done['timetable_entries']} timetable entries loaded.");
    }
}
