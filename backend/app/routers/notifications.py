"""Notification endpoints. Every query is filtered by the logged-in user."""
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.orm import Session

from app.core.security import get_current_user
from app.database import get_db
from app.schemas.notification import NotificationOut, ReadAllOut, UnreadCountOut
from app.services import notification_service as svc

router = APIRouter(prefix="/api/notifications", tags=["notifications"])


@router.get("", response_model=list[NotificationOut])
def list_notifications(
    unread_only: bool = False,
    limit: int = Query(50, ge=1, le=200),
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    return svc.get_notifications(db, current_user.id, unread_only, limit)


@router.get("/unread-count", response_model=UnreadCountOut)
def unread_count(db: Session = Depends(get_db), current_user=Depends(get_current_user)):
    # Polled every few seconds by the bell, so it returns only a number.
    return UnreadCountOut(count=svc.count_unread(db, current_user.id))


@router.patch("/{notification_id}/read", response_model=NotificationOut)
def mark_notification_read(
    notification_id: int,
    db: Session = Depends(get_db),
    current_user=Depends(get_current_user),
):
    notification = svc.mark_read(db, notification_id, current_user.id)
    if notification is None:
        # Same 404 whether the id is missing or belongs to another user.
        raise HTTPException(status_code=404, detail="Notification not found")
    db.commit()  # the service never commits; the router owns the transaction
    db.refresh(notification)
    return notification


@router.post("/read-all", response_model=ReadAllOut)
def mark_all_notifications_read(
    db: Session = Depends(get_db), current_user=Depends(get_current_user)
):
    updated = svc.mark_all_read(db, current_user.id)
    db.commit()
    return ReadAllOut(updated=updated)
