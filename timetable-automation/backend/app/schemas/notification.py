"""Pydantic response models for the notification endpoints."""
from datetime import datetime

from pydantic import BaseModel, ConfigDict

from app.models.notification import NotificationType


class NotificationOut(BaseModel):
    # from_attributes lets Pydantic read straight from the SQLAlchemy object.
    model_config = ConfigDict(from_attributes=True)

    id: int
    type: NotificationType
    message: str
    related_id: int | None
    is_read: bool
    created_at: datetime


class UnreadCountOut(BaseModel):
    count: int


class ReadAllOut(BaseModel):
    updated: int
