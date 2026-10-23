"""Swap request table: a teacher asks to trade one class time for another."""
import enum

from sqlalchemy import CheckConstraint, DateTime, Enum, ForeignKey, Integer, Text, func
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


class SwapStatus(str, enum.Enum):
    pending = "pending"
    approved = "approved"
    rejected = "rejected"
    cancelled = "cancelled"


class SwapRequest(Base):
    __tablename__ = "swap_requests"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    requester_teacher_id: Mapped[int] = mapped_column(ForeignKey("teachers.id"), nullable=False)
    entry_id: Mapped[int] = mapped_column(ForeignKey("timetable_entries.id"), nullable=False)  # requester's class
    target_entry_id: Mapped[int] = mapped_column(ForeignKey("timetable_entries.id"), nullable=False)  # class traded for
    status: Mapped[SwapStatus] = mapped_column(
        Enum(SwapStatus, name="swap_status"), default=SwapStatus.pending, nullable=False
    )
    reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    decided_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    decided_at = mapped_column(DateTime, nullable=True)
    decision_note: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at = mapped_column(DateTime, server_default=func.now(), nullable=False)

    # A class cannot be swapped with itself.
    # NOTE: MySQL enforces CHECK constraints only from 8.0.16; the service also validates this.
    __table_args__ = (
        CheckConstraint("entry_id <> target_entry_id", name="ck_swap_requests_different_entries"),
    )

    # Two FKs point at timetable_entries, so each relationship must say which one it uses.
    requester = relationship("Teacher", foreign_keys=[requester_teacher_id])
    entry = relationship("TimetableEntry", foreign_keys=[entry_id])
    target_entry = relationship("TimetableEntry", foreign_keys=[target_entry_id])
