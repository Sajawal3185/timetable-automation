# TEMPORARY STUB of Sajawal security.py - local testing only.
from fastapi import Depends, Header, HTTPException
from sqlalchemy.orm import Session
from app.database import get_db
from app.models.user import User


def get_current_user(x_user_id: int = Header(None), db: Session = Depends(get_db)):
    user = db.get(User, x_user_id) if x_user_id else None
    if user is None:
        raise HTTPException(status_code=401, detail="Not authenticated")
    return user
