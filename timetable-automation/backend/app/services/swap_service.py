"""Swap workflow: create, approve, reject, cancel.

A swap EXCHANGES the day/start/end of the two timetable entries (proposal 2.1:
"trade class times"). Each entry keeps its own teacher, section and room.

Transaction note: every function here first calls db.rollback(). That ends any
implicit read transaction the caller's dependencies started, so (a) the locking
reads below are the first statements of a fresh transaction and (b) MySQL's
REPEATABLE READ snapshot is taken only AFTER the locks are held. Callers must
therefore pass plain ids, not ORM objects they still need afterwards.
"""
from datetime import datetime, timezone

from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from app.core.permissions import can_decide_swap, role_of
from app.models.notification import NotificationType
from app.models.room import Room
from app.models.swap_request import SwapRequest, SwapStatus
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.models.user import User
from app.services.conflict_checker import Conflict, ProposedSlot, check_conflicts
from app.services.notification_service import notify_many, recipients_for_swap


# ---- Errors; the router maps each to an HTTP code --------------------------

class SwapError(Exception):
    pass

class SwapPermissionError(SwapError):   # -> 403
    pass

class SwapNotFoundError(SwapError):     # -> 404
    pass

class SwapStateError(SwapError):        # -> 409 (already decided / duplicate pending)
    pass

class SwapValidationError(SwapError):   # -> 400
    pass

class SwapConflictError(SwapError):     # -> 409 (hard clash)
    def __init__(self, conflicts: list[Conflict]):
        super().__init__("Swap would cause scheduling conflicts")
        self.conflicts = conflicts


# ---- Helpers ---------------------------------------------------------------

def _utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)  # MySQL DATETIME is naive


def _lock_swap(db: Session, swap_id: int) -> SwapRequest:
    """SELECT ... FOR UPDATE on the swap row. populate_existing forces fresh data."""
    swap = db.execute(
        select(SwapRequest).where(SwapRequest.id == swap_id)
        .with_for_update().execution_options(populate_existing=True)
    ).scalar_one_or_none()
    if swap is None:
        raise SwapNotFoundError("Swap request not found")
    return swap


def _lock_entries(db: Session, ids: set[int]) -> dict[int, TimetableEntry]:
    # ORDER BY id: every transaction locks in ascending id order, so two
    # concurrent swaps touching the same entries cannot deadlock.
    rows = db.execute(
        select(TimetableEntry).where(TimetableEntry.id.in_(list(ids)))
        .order_by(TimetableEntry.id).with_for_update()
        .execution_options(populate_existing=True)
    ).scalars().all()
    return {e.id: e for e in rows}


def _lock_rooms(db: Session, room_ids: set[int]) -> None:
    db.execute(
        select(Room.id).where(Room.id.in_(list(room_ids))).order_by(Room.id).with_for_update()
    ).all()


def build_swap_slots(a: TimetableEntry, b: TimetableEntry) -> list[ProposedSlot]:
    """The POST-swap state: a takes b's time and b takes a's time."""
    return [
        ProposedSlot(b.day, b.start_time, b.end_time, a.teacher_id, a.section_id, a.room_id, a.id),
        ProposedSlot(a.day, a.start_time, a.end_time, b.teacher_id, b.section_id, b.room_id, b.id),
    ]


def preview_swap_conflicts(db: Session, a: TimetableEntry, b: TimetableEntry) -> list[Conflict]:
    """Request-time preview. Not authoritative: approve_swap re-checks under lock."""
    return check_conflicts(db, build_swap_slots(a, b), exclude_entry_ids=(a.id, b.id))


def _notify_swap(db, a, b, type_, swap_id, message) -> None:
    # Both teachers plus the students of both sections (task spec, file 2).
    notify_many(db, recipients_for_swap(db, a, b), type_, message, related_id=swap_id)


# ---- Public operations -----------------------------------------------------

def create_swap_request(db: Session, entry_id: int, target_entry_id: int, reason: str | None) -> SwapRequest:
    """Permission and conflict preview are done by the router before this call."""
    try:
        db.rollback()
        if entry_id == target_entry_id:
            raise SwapValidationError("A class cannot be swapped with itself")

        # Lock both entries so two simultaneous requests cannot both pass the
        # "no pending request on this entry" check (MySQL has no partial unique index).
        entries = _lock_entries(db, {entry_id, target_entry_id})
        if len(entries) != 2:
            raise SwapNotFoundError("Timetable entry not found")

        already_pending = db.execute(
            select(SwapRequest.id).where(
                SwapRequest.status == SwapStatus.pending,
                or_(
                    SwapRequest.entry_id.in_((entry_id, target_entry_id)),
                    SwapRequest.target_entry_id.in_((entry_id, target_entry_id)),
                ),
            ).limit(1)
        ).first()
        if already_pending:
            raise SwapStateError("A pending swap request already exists for one of these classes")

        entry, target = entries[entry_id], entries[target_entry_id]
        swap = SwapRequest(
            requester_teacher_id=entry.teacher_id,  # the requester owns `entry`
            entry_id=entry_id, target_entry_id=target_entry_id,
            status=SwapStatus.pending, reason=reason,
        )
        db.add(swap)
        db.flush()  # populate swap.id for the notification's related_id
        _notify_swap(db, entry, target, NotificationType.swap_requested, swap.id,
                     f"Swap request #{swap.id} has been submitted for approval.")
        db.commit()
        return swap
    except Exception:
        db.rollback()
        raise


def approve_swap(db: Session, swap_id: int, approver_id: int, note: str | None = None) -> SwapRequest:
    """One transaction: lock -> re-check -> apply -> notify -> commit once."""
    try:
        db.rollback()                                        # 1. fresh transaction
        swap = _lock_swap(db, swap_id)                       # 2. lock request row
        if swap.status != SwapStatus.pending:                #    stops double approval
            raise SwapStateError(f"Swap request is already {swap.status.value}")

        entries = _lock_entries(db, {swap.entry_id, swap.target_entry_id})  # 3. ascending ids
        if len(entries) != 2:
            raise SwapNotFoundError("Timetable entry no longer exists")
        _lock_rooms(db, {e.room_id for e in entries.values()})              #    then rooms

        approver = db.get(User, approver_id)
        if approver is None or not can_decide_swap(approver, swap):
            raise SwapPermissionError("You may not decide this swap request")

        entry, target = entries[swap.entry_id], entries[swap.target_entry_id]
        conflicts = preview_swap_conflicts(db, entry, target)               # 4. re-check in lock
        hard = [c for c in conflicts if c.severity == "hard"]
        if hard:
            raise SwapConflictError(hard)

        # 5. apply: exchange the time fields, record the decision.
        entry.day, target.day = target.day, entry.day
        entry.start_time, target.start_time = target.start_time, entry.start_time
        entry.end_time, target.end_time = target.end_time, entry.end_time
        swap.status = SwapStatus.approved
        swap.decided_by = approver_id
        swap.decided_at = _utcnow()
        swap.decision_note = note
        db.flush()

        _notify_swap(db, entry, target, NotificationType.swap_approved, swap.id,
                     f"Swap request #{swap.id} was approved and the timetable has been updated.")
        db.commit()                                          # 6. commit once
        return swap
    except Exception:                                        # 7. rollback + re-raise
        db.rollback()
        raise


def reject_swap(db: Session, swap_id: int, decider_id: int, note: str) -> SwapRequest:
    try:
        db.rollback()
        swap = _lock_swap(db, swap_id)
        if swap.status != SwapStatus.pending:
            raise SwapStateError(f"Swap request is already {swap.status.value}")
        decider = db.get(User, decider_id)
        if decider is None or not can_decide_swap(decider, swap):
            raise SwapPermissionError("You may not decide this swap request")

        swap.status = SwapStatus.rejected
        swap.decided_by = decider_id
        swap.decided_at = _utcnow()
        swap.decision_note = note
        entry, target = db.get(TimetableEntry, swap.entry_id), db.get(TimetableEntry, swap.target_entry_id)
        _notify_swap(db, entry, target, NotificationType.swap_rejected, swap.id,
                     f"Swap request #{swap.id} was rejected.")
        db.commit()
        return swap
    except Exception:
        db.rollback()
        raise


def cancel_swap(db: Session, swap_id: int, user_id: int) -> SwapRequest:
    """Requester only, and only while still pending."""
    try:
        db.rollback()
        swap = _lock_swap(db, swap_id)
        if swap.requester.user_id != user_id:
            raise SwapPermissionError("Only the requester can cancel this swap request")
        if swap.status != SwapStatus.pending:
            raise SwapStateError(f"Swap request is already {swap.status.value}")
        swap.status = SwapStatus.cancelled
        db.commit()
        return swap
    except Exception:
        db.rollback()
        raise


def list_swaps_for_user(db: Session, user) -> list[SwapRequest]:
    """Teachers: their own requests. HOD: pending requests of their department.
    Admin: all pending requests (an admin has no single department)."""
    base = select(SwapRequest).order_by(SwapRequest.created_at.desc(), SwapRequest.id.desc())
    role = role_of(user)
    if role == "teacher":
        teacher = db.execute(select(Teacher).where(Teacher.user_id == user.id)).scalar_one_or_none()
        if teacher is None:
            return []
        stmt = base.where(SwapRequest.requester_teacher_id == teacher.id)
    elif role == "hod":
        stmt = (
            base.join(Teacher, Teacher.id == SwapRequest.requester_teacher_id)
            .where(Teacher.department_id == user.department_id, SwapRequest.status == SwapStatus.pending)
        )
    elif role == "admin":
        stmt = base.where(SwapRequest.status == SwapStatus.pending)
    else:
        raise SwapPermissionError("Not allowed to list swap requests")
    return list(db.execute(stmt).scalars())
