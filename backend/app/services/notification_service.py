"""Notification business logic.

IMPORTANT: nothing in this file calls commit(). Functions only add/modify rows
in the caller's session. That way, when a notification is created inside a swap
transaction, it is committed with the swap and rolled back with it on failure.
Callers (swap_service, routers) own the commit.
"""
from typing import Iterable

from sqlalchemy import func, select, update
from sqlalchemy.orm import Session

from app.models.notification import Notification, NotificationType
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.models.user import User


def create_notification(
    db: Session,
    user_id: int,
    type: NotificationType,
    message: str,
    related_id: int | None = None,
) -> Notification:
    """Add one notification row to the session (no commit)."""
    notification = Notification(
        user_id=user_id, type=type, message=message, related_id=related_id
    )
    db.add(notification)
    return notification


def notify_many(
    db: Session,
    user_ids: Iterable[int],
    type: NotificationType,
    message: str,
    related_id: int | None = None,
) -> list[Notification]:
    """Send the same notification to many users (e.g. a whole section)."""
    # set() so a person who appears twice (e.g. teacher of both classes) gets one row.
    return [
        create_notification(db, uid, type, message, related_id)
        for uid in sorted(set(user_ids))
    ]


def get_notifications(
    db: Session, user_id: int, unread_only: bool = False, limit: int = 50
) -> list[Notification]:
    """Newest first, always scoped to one user."""
    stmt = select(Notification).where(Notification.user_id == user_id)
    if unread_only:
        stmt = stmt.where(Notification.is_read.is_(False))
    stmt = stmt.order_by(Notification.created_at.desc(), Notification.id.desc()).limit(limit)
    return list(db.execute(stmt).scalars())


def count_unread(db: Session, user_id: int) -> int:
    """Cheap count used by the bell's polling endpoint."""
    stmt = (
        select(func.count())
        .select_from(Notification)
        .where(Notification.user_id == user_id, Notification.is_read.is_(False))
    )
    return db.execute(stmt).scalar_one()


def mark_read(db: Session, notification_id: int, user_id: int) -> Notification | None:
    """Mark one notification read. Returns None if it does not exist OR belongs
    to another user, so the router can answer 404 in both cases and never reveal
    that someone else's notification exists."""
    notification = db.execute(
        select(Notification).where(
            Notification.id == notification_id, Notification.user_id == user_id
        )
    ).scalar_one_or_none()
    if notification is not None:
        notification.is_read = True
    return notification


def mark_all_read(db: Session, user_id: int) -> int:
    """Mark every unread notification of this user as read; returns how many."""
    result = db.execute(
        update(Notification)
        .where(Notification.user_id == user_id, Notification.is_read.is_(False))
        .values(is_read=True)
    )
    return result.rowcount


# ---- Who gets notified (task spec, file 2) ---------------------------------

def _teacher_user_ids(db: Session, teacher_ids: set[int]) -> set[int]:
    rows = db.execute(select(Teacher.user_id).where(Teacher.id.in_(list(teacher_ids))))
    return set(rows.scalars())


def _student_user_ids(db: Session, section_ids: set[int]) -> set[int]:
    rows = db.execute(
        select(User.id).where(User.section_id.in_(list(section_ids)), User.role == "student")
    )
    return set(rows.scalars())


def recipients_for_swap(
    db: Session, entry_a: TimetableEntry, entry_b: TimetableEntry
) -> set[int]:
    """A swap notifies both teachers plus the students of both sections."""
    return _teacher_user_ids(db, {entry_a.teacher_id, entry_b.teacher_id}) | _student_user_ids(
        db, {entry_a.section_id, entry_b.section_id}
    )


def recipients_for_makeup(db: Session, teacher_id: int, section_id: int) -> set[int]:
    """A makeup class notifies the teacher plus the section's students."""
    return _teacher_user_ids(db, {teacher_id}) | _student_user_ids(db, {section_id})
