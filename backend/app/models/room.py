"""rooms table (Sajawal)."""
from datetime import datetime
from sqlalchemy import CheckConstraint, DateTime, Integer, String, func
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class Room(Base):
    __tablename__ = "rooms"
    __table_args__ = (
        CheckConstraint("capacity IS NULL OR capacity > 0", name="room_capacity_positive"),
        CheckConstraint("room_type IN ('lecture', 'lab')", name="room_type_lowercase"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(50), unique=True)
    canonical_code: Mapped[str | None] = mapped_column(String(50), nullable=True, unique=True)
    # Nullable with no fake default: unknown capacity is None until verified by admin/HOD
    capacity: Mapped[int | None] = mapped_column(Integer, nullable=True)
    room_type: Mapped[str] = mapped_column(String(20), default="lecture")
    building: Mapped[str | None] = mapped_column(String(50), nullable=True, index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())