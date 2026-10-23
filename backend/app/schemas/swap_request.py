"""Pydantic request/response models for the swap endpoints."""
from datetime import datetime
from typing import Annotated

from pydantic import BaseModel, ConfigDict, StringConstraints

from app.models.swap_request import SwapStatus

# A note that must contain at least one non-space character.
RequiredNote = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1)]


class SwapRequestCreate(BaseModel):
    entry_id: int
    target_entry_id: int
    reason: str | None = None


class SwapApprove(BaseModel):
    note: str | None = None


class SwapReject(BaseModel):
    note: RequiredNote  # rejecting requires a note (task spec, file 7)


class ConflictOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    type: str  # teacher | section | room
    entry_id: int | None
    detail: str
    severity: str  # hard | soft


class SwapRequestOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    requester_teacher_id: int
    entry_id: int
    target_entry_id: int
    status: SwapStatus
    reason: str | None
    decided_by: int | None
    decided_at: datetime | None
    decision_note: str | None
    created_at: datetime


class SwapRequestCreated(SwapRequestOut):
    # Soft conflicts do not block; they are returned so the UI can show them.
    warnings: list[ConflictOut] = []
