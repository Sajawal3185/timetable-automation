<?php

namespace App\Console\Commands;

use App\Models\Room;
use Illuminate\Console\Command;

class RoomsCapacities extends Command
{
    protected $signature = 'rooms:capacities {file : CSV with the columns name,capacity} {--dry-run : Show what would change, save nothing}';

    protected $description = 'Set room capacities from a CSV (name,capacity)';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read $path");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $nameCol = array_search('name', $header, true);
        $capCol = array_search('capacity', $header, true);
        if ($nameCol === false || $capCol === false) {
            fclose($handle);
            $this->error('The CSV needs the columns name,capacity');

            return self::FAILURE;
        }

        $byName = [];
        foreach (Room::all() as $room) {
            $byName[mb_strtolower(trim($room->name))][] = $room;
        }

        $dry = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;
        $unmatched = [];
        $ambiguous = [];
        $bad = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($row === [null] || $row === []) {
                continue;
            }
            $name = trim((string) ($row[$nameCol] ?? ''));
            $cap = trim((string) ($row[$capCol] ?? ''));

            if ($name === '') {
                $bad[] = "line $line: no name";
                continue;
            }
            if ($cap === '') {
                $skipped++;
                continue;
            }
            if (! preg_match('/^[1-9]\d*$/', $cap)) {
                $bad[] = "line $line: '$name' has capacity '$cap' (must be a positive whole number)";
                continue;
            }

            $matches = $byName[mb_strtolower($name)] ?? [];
            if ($matches === []) {
                $unmatched[] = $name;
                continue;
            }
            if (count($matches) > 1) {
                $ambiguous[] = $name;
                continue;
            }

            if (! $dry) {
                $matches[0]->forceFill(['capacity' => (int) $cap])->save();
            }
            $updated++;
            $this->line(($dry ? '[dry-run] ' : '').$matches[0]->name." -> $cap");
        }
        fclose($handle);

        $this->info(($dry ? 'Would update ' : 'Updated ')."$updated room(s). Skipped $skipped with a blank capacity.");
        foreach ($unmatched as $n) {
            $this->warn("No room matches: $n");
        }
        foreach ($ambiguous as $n) {
            $this->warn("More than one room has the name (not changed): $n");
        }
        foreach ($bad as $b) {
            $this->error("Refused, $b");
        }

        return $bad === [] ? self::SUCCESS : self::FAILURE;
    }
}