<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Room;
use App\Models\SwapRequest;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The swap workflow (backend/app/services/swap_service.py): create, approve,
 * reject, cancel, list. A swap EXCHANGES the day and times of two classes;
 * each keeps its own teacher, section and room.
 *
 * Every change runs in one transaction that first locks the rows it reads
 * (FOR UPDATE: the swap, then the two entries in id order, then their rooms),
 * so two people deciding at the same moment can't both win, and a clash is
 * re-checked with nothing able to change underneath. A failure anywhere
 * (abort() included) rolls everything back, notifications too.
 */
final class SwapService
{
    public const CONFLICT_MESSAGE = 'Swap would cause scheduling conflicts';

    /** The two classes as they would be after the swap: a at b's time, b at a's. */
    public static function swapSlots(TimetableEntry $a, TimetableEntry $b): array
    {
        return [ConflictChecker::slot($a, $b), ConflictChecker::slot($b, $a)];
    }

    /** Request-time preview; approve() checks again under lock. */
    public static function previewConflicts(TimetableEntry $a, TimetableEntry $b): array
    {
        return ConflictChecker::check(self::swapSlots($a, $b), [$a->id, $b->id]);
    }

    /** Only the teacher who owns the class (an imported class may have no teacher: nobody owns it). */
    public static function canRequest(User $user, TimetableEntry $entry): bool
    {
        return $user->role === Roles::TEACHER && $entry->teacher !== null && $entry->teacher->user_id === $user->id;
    }

    /** An admin, or the HOD of the requester's department; never the requester themselves. */
    public static function canDecide(User $user, SwapRequest $swap): bool
    {
        $requester = $swap->requester;
        if ($requester->user_id === $user->id) {
            return false;
        }

        return match ($user->role) {
            Roles::ADMIN => true,
            Roles::HOD => $user->department_id !== null && $user->department_id === $requester->department_id,
            default => false,
        };
    }

    public static function create(int $entryId, int $targetEntryId, ?string $reason): SwapRequest
    {
        return DB::transaction(function () use ($entryId, $targetEntryId, $reason) {
            // Locking both entries stops two requests at once from both passing the
            // "no pending request" check (MySQL has no partial unique index for it).
            $entries = self::lockEntries([$entryId, $targetEntryId]);
            if ($entries->count() !== 2) {
                abort(404, 'Timetable entry not found');
            }
            $pending = SwapRequest::where('status', 'pending')
                ->where(fn ($q) => $q->whereIn('entry_id', [$entryId, $targetEntryId])->orWhereIn('target_entry_id', [$entryId, $targetEntryId]))
                ->exists();
            if ($pending) {
                abort(409, 'A pending swap request already exists for one of these classes');
            }

            [$entry, $target] = [$entries[$entryId], $entries[$targetEntryId]];
            self::refuseSameClass($entry, $target);
            $swap = SwapRequest::create([
                'requester_teacher_id' => $entry->teacher_id,   // the requester owns `entry`
                'entry_id' => $entryId, 'target_entry_id' => $targetEntryId,
                'status' => 'pending', 'reason' => $reason,
            ]);
            self::notify($entry, $target, 'swap_requested', $swap->id, "Swap request #{$swap->id} has been submitted for approval.");

            return $swap;
        });
    }

    public static function approve(int $swapId, User $approver, ?string $note): SwapRequest
    {
        return DB::transaction(function () use ($swapId, $approver, $note) {
            $swap = self::lockPendingSwap($swapId);
            $entries = self::lockEntries([$swap->entry_id, $swap->target_entry_id]);
            if ($entries->count() !== 2) {
                abort(404, 'Timetable entry no longer exists');
            }
            Room::whereIn('id', $entries->pluck('room_id')->filter()->all())->orderBy('id')->lockForUpdate()->get();

            if (! self::canDecide($approver, $swap)) {
                abort(403, 'You may not decide this swap request');
            }
            [$entry, $target] = [$entries[$swap->entry_id], $entries[$swap->target_entry_id]];
            self::refuseSameClass($entry, $target);   // requests made before this check existed
            if ($hard = ConflictChecker::hard(self::previewConflicts($entry, $target))) {
                throw new ApiException(409, ['message' => self::CONFLICT_MESSAGE, 'conflicts' => $hard]);
            }

            // Exchange the times; record the decision.
            $old = $entry->only(['day_of_week', 'start_time', 'end_time']);
            $entry->update($target->only(['day_of_week', 'start_time', 'end_time']));
            $target->update($old);
            $swap->update(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now('UTC'), 'decision_note' => $note]);

            self::notify($entry, $target, 'swap_approved', $swap->id,
                "Swap request #{$swap->id} was approved and the timetable has been updated.");

            return $swap;
        });
    }

    public static function reject(int $swapId, User $decider, string $note): SwapRequest
    {
        return DB::transaction(function () use ($swapId, $decider, $note) {
            $swap = self::lockPendingSwap($swapId);
            if (! self::canDecide($decider, $swap)) {
                abort(403, 'You may not decide this swap request');
            }
            $swap->update(['status' => 'rejected', 'decided_by' => $decider->id, 'decided_at' => now('UTC'), 'decision_note' => $note]);
            self::notify($swap->entry, $swap->targetEntry, 'swap_rejected', $swap->id, "Swap request #{$swap->id} was rejected.");

            return $swap;
        });
    }

    /** The requester only, and only while it is still pending. */
    public static function cancel(int $swapId, User $user): SwapRequest
    {
        return DB::transaction(function () use ($swapId, $user) {
            $swap = self::lockSwap($swapId);
            if ($swap->requester->user_id !== $user->id) {
                abort(403, 'Only the requester can cancel this swap request');
            }
            self::refuseDecided($swap);
            $swap->update(['status' => 'cancelled']);

            return $swap;
        });
    }

    /**
     * Teachers: their own requests. HOD: the pending requests of their
     * department. Admin: every pending request (an admin has no department).
     *
     * @return Collection<int, SwapRequest>
     */
    public static function listFor(User $user): Collection
    {
        $query = SwapRequest::query()->orderByDesc('created_at')->orderByDesc('id');

        return match ($user->role) {
            Roles::TEACHER => $user->teacher
                ? $query->where('requester_teacher_id', $user->teacher->id)->get()
                : collect(),
            Roles::HOD => $query->where('status', 'pending')
                ->whereHas('requester', fn ($q) => $q->where('department_id', $user->department_id))->get(),
            Roles::ADMIN => $query->where('status', 'pending')->get(),
            default => abort(403, 'Not allowed to list swap requests'),
        };
    }

    // ------------------------------------------------------------------ helpers

    /** @return Collection<int, TimetableEntry> keyed by id, locked in ascending id order (no deadlocks) */
    private static function lockEntries(array $ids): Collection
    {
        return TimetableEntry::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private static function lockSwap(int $swapId): SwapRequest
    {
        return SwapRequest::whereKey($swapId)->lockForUpdate()->first() ?? abort(404, 'Swap request not found');
    }

    private static function lockPendingSwap(int $swapId): SwapRequest
    {
        $swap = self::lockSwap($swapId);
        self::refuseDecided($swap);

        return $swap;
    }

    private static function refuseDecided(SwapRequest $swap): void
    {
        if ($swap->status !== 'pending') {
            abort(409, "Swap request is already {$swap->status}");   // stops a double approval
        }
    }

    /**
     * Two sessions of one class: swapping them changes nothing, and the update
     * would trip uq_timetable_term_sec_course_slot_grp halfway through.
     */
    private static function refuseSameClass(TimetableEntry $a, TimetableEntry $b): void
    {
        if ($a->term === $b->term && $a->section_id === $b->section_id
            && $a->course_id === $b->course_id && $a->group_label === $b->group_label) {
            abort(400, 'Both classes are the same course for the same section; nothing to swap');
        }
    }

    private static function notify(TimetableEntry $a, TimetableEntry $b, string $type, int $swapId, string $message): void
    {
        Notifier::notifyMany(Notifier::recipientsForSwap($a, $b), $type, $message, $swapId);
    }
}
