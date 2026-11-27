<?php

namespace App\Services;

use App\Models\TimetableEntry;
use App\Support\Fmt;

/**
 * Teacher, section and room clashes (backend/app/services/conflict_checker.py).
 * Read-only. Used by swaps now, and by the makeup solver later.
 *
 * A proposed slot is one class as it WOULD be after the change:
 *   ['day' => 'Monday', 'start' => '09:00:00', 'end' => '10:00:00',
 *    'teacher_id' => 1, 'section_id' => 2, 'room_id' => 3, 'entry_id' => 4 or null]
 * A conflict is ['type' => teacher|section|room, 'entry_id', 'detail', 'severity' => hard|soft]
 * (ConflictOut in schemas/swap_request.py). Every clash type is hard (scheduler_rules.py).
 */
final class ConflictChecker
{
    public const TYPES = ['teacher', 'section', 'room'];

    /** Types that block a change outright (HARD_CONFLICT_TYPES in scheduler_rules.py). */
    public const HARD = ['teacher', 'section', 'room'];

    /**
     * Every clash the proposed slots would cause.
     *
     * @param  list<array<string, mixed>>  $slots
     * @param  list<int>  $excludeEntryIds  entries being moved: not counted as existing rows,
     *                                      or a swapped class would clash with its own old self
     * @return list<array<string, mixed>>
     */
    public static function check(array $slots, array $excludeEntryIds = []): array
    {
        $conflicts = [];

        // 1) Each proposed slot against the entries already in the timetable.
        foreach ($slots as $slot) {
            $existing = TimetableEntry::query()
                ->where('day_of_week', $slot['day'])
                ->where('start_time', '<', $slot['end'])   // overlap, not equality:
                ->where('end_time', '>', $slot['start'])   // 09-10 and 10-11 only touch
                ->where(function ($q) use ($slot) {
                    $q->whereRaw('1 = 0');
                    foreach (self::TYPES as $type) {
                        if ($slot[$type.'_id'] !== null) {   // a NULL teacher/room clashes with nothing
                            $q->orWhere($type.'_id', $slot[$type.'_id']);
                        }
                    }
                })
                ->when($excludeEntryIds !== [], fn ($q) => $q->whereNotIn('id', $excludeEntryIds))
                ->orderBy('id')
                ->get();

            foreach ($existing as $e) {
                $when = "on {$slot['day']} ".Fmt::time($e->start_time).'-'.Fmt::time($e->end_time);
                foreach (self::TYPES as $type) {
                    if ($slot[$type.'_id'] !== null && $e->{$type.'_id'} === $slot[$type.'_id']) {
                        $conflicts[] = self::conflict($type, $e->id, "$type clash with entry {$e->id} $when");
                    }
                }
            }
        }

        // 2) The proposed slots against each other: the moved entries were excluded
        //    above, so two moved classes landing on each other would go unseen.
        foreach ($slots as $i => $a) {
            foreach (array_slice($slots, $i + 1) as $b) {
                if ($a['day'] !== $b['day'] || ! ($a['start'] < $b['end'] && $a['end'] > $b['start'])) {
                    continue;
                }
                foreach (self::TYPES as $type) {
                    if ($a[$type.'_id'] !== null && $a[$type.'_id'] === $b[$type.'_id']) {
                        $conflicts[] = self::conflict($type, $b['entry_id'], "$type clash between two proposed slots on {$a['day']}");
                    }
                }
            }
        }

        return $conflicts;
    }

    /** @return list<array<string, mixed>> only the clashes that block */
    public static function hard(array $conflicts): array
    {
        return array_values(array_filter($conflicts, fn ($c) => $c['severity'] === 'hard'));
    }

    /** One class as a proposed slot, at another time (or its own when $at is null). */
    public static function slot(TimetableEntry $entry, ?TimetableEntry $at = null): array
    {
        $at ??= $entry;

        return [
            'day' => $at->day_of_week, 'start' => Fmt::time($at->start_time), 'end' => Fmt::time($at->end_time),
            'teacher_id' => $entry->teacher_id, 'section_id' => $entry->section_id, 'room_id' => $entry->room_id,
            'entry_id' => $entry->id,
        ];
    }

    private static function conflict(string $type, ?int $entryId, string $detail): array
    {
        return ['type' => $type, 'entry_id' => $entryId, 'detail' => $detail,
            'severity' => in_array($type, self::HARD, true) ? 'hard' : 'soft'];
    }
}
