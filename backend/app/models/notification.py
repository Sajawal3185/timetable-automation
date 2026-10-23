"""Notification table: one row per message per recipient."""
import enum

from sqlalchemy import Boolean, DateTime, Enum, ForeignKey, Index, Integer, Text, func
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class NotificationType(str, enum.Enum):
    """Kinds of notification the system can send (task spec, file 1)."""
    schedule_changed = "schedule_changed"
    swap_requested = "swap_requested"
    swap_approved = "swap_approved"
    swap_rejected = "swap_rejected"
    makeup_added = "makeup_added"


class Notification(Base):
    __tablename__ = "notifications"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), nullable=False)
    type: Mapped[NotificationType] = mapped_column(
        Enum(NotificationType, name="notification_type"), nullable=False
    )
    message: Mapped[str] = mapped_column(Text, nullable=False)
    # Optional pointer to the thing the notification is about (e.g. swap request id).
    related_id: Mapped[int | None] = mapped_column(Integer, nullable=True)
    is_read: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    created_at = mapped_column(DateTime, server_default=func.now(), nullable=False)

    # Every poll from the bell filters on (user_id, is_read), so index exactly that.
    __table_args__ = (Index("ix_notifications_user_id_is_read", "user_id", "is_read"),)
