"""events table (Wajiha) - institutional event calendar, proposal 4.6."""
from datetime import date, datetime
from enum import Enum as PyEnum
from sqlalchemy import Date, DateTime, Enum, ForeignKey, String, Text, CheckConstraint, func
from sqlalchemy.orm import Mapped, mapped_column
from database import Base


class EventType(str, PyEnum):
    DEADLINE = "deadline"
    EVENT = "event"
    HOLIDAY = "holiday"
    DEPARTMENT = "department"


class Event(Base):
    __tablename__ = "events"

    id: Mapped[int] = mapped_column(primary_key=True)
    title: Mapped[str] = mapped_column(String(200))
    event_type: Mapped[EventType] = mapped_column(
        Enum(EventType, name="event_type_enum", values_callable=lambda obj: [e.value for e in obj]),
        index=True
    )
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    start_date: Mapped[date] = mapped_column(Date, index=True)
    end_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    location: Mapped[str | None] = mapped_column(String(150), nullable=True)
    department_id: Mapped[int | None] = mapped_column(ForeignKey("departments.id"), nullable=True)
    created_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())

    __table_args__ = (
        CheckConstraint("(end_date IS NULL) OR (end_date >= start_date)", name="check_end_date_after_start"),
    )