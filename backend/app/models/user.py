"""TEMPORARY STUB of Sajawal's User model - local testing only. Delete when his real file is merged."""
from sqlalchemy import ForeignKey, String, Integer, Time
from sqlalchemy.orm import Mapped, mapped_column
from database import Base


class User(Base):
    __tablename__ = "users"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(100))
    email: Mapped[str] = mapped_column(String(150), unique=True)
    role: Mapped[str] = mapped_column(String(20))
