"""Swap endpoints. Thin: validate, call the service, map errors to HTTP codes."""
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session

from app.core.permissions import can_request_swap, require_roles
from app.database import get_db
from app.models.timetable_entry import TimetableEntry
from app.schemas.swap_request import (
    ConflictOut, SwapApprove, SwapReject, SwapRequestCreate, SwapRequestCreated, SwapRequestOut,
)
from app.services import swap_service as svc

router = APIRouter(prefix="/api/swap-requests", tags=["swaps"])


def _http_error(exc: svc.SwapError) -> HTTPException:
    """403 permission, 404 missing, 409 conflict or already decided (400 for bad input)."""
    if isinstance(exc, svc.SwapPermissionError):
        return HTTPException(403, str(exc))
    if isinstance(exc, svc.SwapNotFoundError):
        return HTTPException(404, str(exc))
    if isinstance(exc, svc.SwapConflictError):
        return HTTPException(409, {
            "message": str(exc),
            "conflicts": [ConflictOut.model_validate(c).model_dump() for c in exc.conflicts],
        })
    if isinstance(exc, svc.SwapStateError):
        return HTTPException(409, str(exc))
    return HTTPException(400, str(exc))


@router.post("", response_model=SwapRequestCreated, status_code=201)
def create_swap(
    body: SwapRequestCreate,
    db: Session = Depends(get_db),
    current_user=Depends(require_roles("teacher")),
):
    if body.entry_id == body.target_entry_id:
        raise HTTPException(400, "A class cannot be swapped with itself")
    entry = db.get(TimetableEntry, body.entry_id)
    target = db.get(TimetableEntry, body.target_entry_id)
    if entry is None or target is None:
        raise HTTPException(404, "Timetable entry not found")
    if not can_request_swap(current_user, entry):
        raise HTTPException(403, "You can only request swaps for your own classes")

    # Conflict preview: a hard clash is rejected now instead of waiting for the approver.
    conflicts = svc.preview_swap_conflicts(db, entry, target)
    hard = [c for c in conflicts if c.severity == "hard"]
    if hard:
        raise _http_error(svc.SwapConflictError(hard))
    warnings = [ConflictOut.model_validate(c) for c in conflicts if c.severity != "hard"]

    try:
        swap = svc.create_swap_request(db, body.entry_id, body.target_entry_id, body.reason)
    except svc.SwapError as exc:
        raise _http_error(exc)
    return SwapRequestCreated(**SwapRequestOut.model_validate(swap).model_dump(), warnings=warnings)


@router.get("", response_model=list[SwapRequestOut])
def list_swaps(
    db: Session = Depends(get_db),
    current_user=Depends(require_roles("teacher", "hod", "admin")),
):
    try:
        return svc.list_swaps_for_user(db, current_user)
    except svc.SwapError as exc:
        raise _http_error(exc)


@router.post("/{swap_id}/approve", response_model=SwapRequestOut)
def approve_swap(
    swap_id: int,
    body: SwapApprove | None = None,
    db: Session = Depends(get_db),
    current_user=Depends(require_roles("hod", "admin")),
):
    approver_id = current_user.id  # read before the service ends the session's transaction
    try:
        return svc.approve_swap(db, swap_id, approver_id, body.note if body else None)
    except svc.SwapError as exc:
        raise _http_error(exc)


@router.post("/{swap_id}/reject", response_model=SwapRequestOut)
def reject_swap(
    swap_id: int,
    body: SwapReject,
    db: Session = Depends(get_db),
    current_user=Depends(require_roles("hod", "admin")),
):
    decider_id = current_user.id
    try:
        return svc.reject_swap(db, swap_id, decider_id, body.note)
    except svc.SwapError as exc:
        raise _http_error(exc)


@router.post("/{swap_id}/cancel", response_model=SwapRequestOut)
def cancel_swap(
    swap_id: int,
    db: Session = Depends(get_db),
    current_user=Depends(require_roles("teacher")),
):
    user_id = current_user.id
    try:
        return svc.cancel_swap(db, swap_id, user_id)
    except svc.SwapError as exc:
        raise _http_error(exc)
