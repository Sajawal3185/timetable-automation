<?php

namespace App\Services;

/**
 * Hard and soft scheduling rules (backend/app/services/scheduler_rules.py).
 * Pure functions: no database. The makeup solver and the booking use them.
 *
 * A slot is ['day', 'start' => 'HH:MM:SS', 'end', 'section_id', 'course_id',
 *            'teacher_id' => ?int, 'room_id' => ?int, 'elective_group' => ?string,
 *            'group_label' => string, 'entry_id' => ?int, 'entry_type' => timetable|makeup]
 * (missing keys count as null / "" / "timetable", see slot()).
 * A violation is ['level' => hard|soft, 'kind', 'message', 'entry_id'].
 */
final class SchedulerRules
{
    /** Fills the optional keys of a slot. */
    public static function slot(array $slot): array
    {
        return $slot + ['teacher_id' => null, 'room_id' => null, 'elective_group' => null, 'group_label' => '',
            'entry_id' => null, 'entry_type' => 'timetable'];
    }

    /**
     * A teacher, room or section can't be in two places at overlapping times. Not a clash:
     * the slot itself (same id AND same table), parallel electives of one section sharing
     * an elective_group, and different lab groups (Group A / Group B) of one section.
     *
     * @param  list<array>  $existing
     * @return list<array>
     */
    public static function hardClashes(array $candidate, array $existing): array
    {
        $c = self::slot($candidate);
        $violations = [];
        foreach ($existing as $s) {
            $s = self::slot($s);
            if ($c['entry_id'] !== null && $s['entry_id'] === $c['entry_id'] && $s['entry_type'] === $c['entry_type']) {
                continue;
            }
            if ($s['day'] !== $c['day'] || ! self::overlaps($c['start'], $c['end'], $s['start'], $s['end'])) {
                continue;
            }
            $when = self::hm($s['start']).'-'.self::hm($s['end']);

            if ($c['teacher_id'] !== null && $c['teacher_id'] === $s['teacher_id']) {
                $violations[] = self::violation('teacher_clash', "Teacher #{$c['teacher_id']} is already booked $when", $s['entry_id']);
            }
            if ($c['room_id'] !== null && $c['room_id'] === $s['room_id']) {
                $violations[] = self::violation('room_clash', "Room #{$c['room_id']} is already booked $when", $s['entry_id']);
            }
            if ($c['section_id'] === $s['section_id']) {
                $sameElective = (bool) $c['elective_group'] && $c['elective_group'] === $s['elective_group'];
                $otherLabGroup = $c['group_label'] !== '' && $s['group_label'] !== '' && $c['group_label'] !== $s['group_label'];
                if (! $sameElective && ! $otherLabGroup) {
                    $violations[] = self::violation('section_clash', "Section #{$c['section_id']} is already booked $when", $s['entry_id']);
                }
            }
        }

        return $violations;
    }

    /**
     * The room must fit the class: a lab course needs a lab, a lecture must not take a lab
     * (labs are scarce), and the seats must be known and enough.
     *
     * @return list<array>
     */
    public static function roomSuitability(?int $studentCount, ?int $roomCapacity, bool $requiresLab = false, ?string $roomType = 'lecture'): array
    {
        $violations = [];
        $isLab = strtolower($roomType ?: 'lecture') === 'lab';
        if ($requiresLab && ! $isLab) {
            $violations[] = self::violation('room_type_mismatch', "Lab course requires a room with room_type='lab'");
        } elseif (! $requiresLab && $isLab) {
            $violations[] = self::violation('room_type_mismatch', 'Lecture course cannot be booked in a lab room');
        }

        if ($studentCount === null || $roomCapacity === null) {
            $violations[] = self::violation('unknown_capacity',
                'Cannot verify seating fit because section student_count or room capacity is unknown (None)');
        } elseif ($roomCapacity < $studentCount) {
            $violations[] = self::violation('room_capacity', "Room capacity ($roomCapacity) is smaller than section size ($studentCount)");
        }

        return $violations;
    }

    /**
     * Soft rule: a section's day shouldn't have a gap longer than $maxGap minutes.
     *
     * @param  list<array>  $sectionDaySlots  one section, one day
     * @return list<array>
     */
    public static function softSectionGaps(array $sectionDaySlots, int $maxGap = 120): array
    {
        if (count($sectionDaySlots) < 2) {
            return [];
        }
        usort($sectionDaySlots, fn ($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        $violations = [];
        $latestEnd = $sectionDaySlots[0]['end'];
        foreach (array_slice($sectionDaySlots, 1) as $s) {
            $s = self::slot($s);
            $gap = self::minutes($s['start']) - self::minutes($latestEnd);
            if ($gap > $maxGap) {
                $violations[] = ['level' => 'soft', 'kind' => 'long_student_gap', 'entry_id' => $s['entry_id'],
                    'message' => "Section #{$s['section_id']} has a $gap-minute gap on {$s['day']} between ".self::hm($latestEnd).' and '.self::hm($s['start'])];
            }
            if ($s['end'] > $latestEnd) {
                $latestEnd = $s['end'];
            }
        }

        return $violations;
    }

    /** Half-open [start, end): 09-10 and 10-11 touch but don't overlap. */
    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $endA > $startB;
    }

    public static function minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return (int) $h * 60 + (int) $m;
    }

    private static function hm(string $time): string
    {
        return substr($time, 0, 5);
    }

    private static function violation(string $kind, string $message, ?int $entryId = null): array
    {
        return ['level' => 'hard', 'kind' => $kind, 'message' => $message, 'entry_id' => $entryId];
    }
}
