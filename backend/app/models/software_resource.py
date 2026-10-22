"""software_resources table (Wajiha) - course software directory, proposal 4.4."""
from datetime import datetime
from sqlalchemy import DateTime, ForeignKey, String, Text, func
from sqlalchemy.orm import Mapped, mapped_column
from database import Base


class SoftwareResource(Base):
    __tablename__ = "software_resources"

    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(120), index=True)
    category: Mapped[str | None] = mapped_column(String(50), nullable=True)  # IDE, database, design...
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    download_url: Mapped[str] = mapped_column(String(500))
    instructions: Mapped[str | None] = mapped_column(Text, nullable=True)
    course_id: Mapped[int | None] = mapped_column(ForeignKey("courses.id"), nullable=True, index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
