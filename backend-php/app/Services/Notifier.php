<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Support\Roles;

/**
 * Creating notifications (backend/app/services/notification_service.py).
 * Nothing here starts or commits a transaction: called inside a swap's
 * DB::transaction, the notifications are saved with the swap or not at all.
 */
final class Notifier
{
    /**
     * The same message to many users; someone listed twice gets one notification.
     *
     * @param  iterable<int>  $userIds
     */
    public static function notifyMany(iterable $userIds, string $type, string $message, ?int $relatedId = null): void
    {
        $ids = array_unique([...$userIds]);
        sort($ids);
        foreach ($ids as $userId) {
            Notification::create(['user_id' => $userId, 'type' => $type, 'message' => $message, 'related_id' => $relatedId]);
        }
    }

    /** A swap tells both teachers and the students of both sections. @return list<int> */
    public static function recipientsForSwap(TimetableEntry $a, TimetableEntry $b): array
    {
        return [...self::teacherUserIds([$a->teacher_id, $b->teacher_id]), ...self::studentUserIds([$a->section_id, $b->section_id])];
    }

    /** A makeup class tells its teacher and the section's students. @return list<int> */
    public static function recipientsForMakeup(int $teacherId, int $sectionId): array
    {
        return [...self::teacherUserIds([$teacherId]), ...self::studentUserIds([$sectionId])];
    }

    /** Imported teachers often have no login yet (user_id NULL): they are skipped. */
    private static function teacherUserIds(array $teacherIds): array
    {
        return Teacher::whereIn('id', array_filter($teacherIds))->whereNotNull('user_id')->pluck('user_id')->all();
    }

    private static function studentUserIds(array $sectionIds): array
    {
        return User::whereIn('section_id', array_filter($sectionIds))->where('role', Roles::STUDENT)->pluck('id')->all();
    }
}
