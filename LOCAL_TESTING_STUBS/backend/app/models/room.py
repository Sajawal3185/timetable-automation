"""TEMPORARY STUB of Sajawal's Room model - local testing only. Delete when his real file is merged."""
from sqlalchemy import ForeignKey, String, Integer, Time
from sqlalchemy.orm import Mapped, mapped_column
from app.database import Base


class Room(Base):
    __tablename__ = "rooms"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(50), unique=True)
    capacity: Mapped[int] = mapped_column(Integer)
    building: Mapped[str | None] = mapped_column(String(50), nullable=True)
