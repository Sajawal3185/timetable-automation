"""Role checks as FastAPI dependencies, plus swap-specific permission rules.

Depends on get_current_user from core/security.py (blocked until login is merged).
"""
from fastapi import Depends, HTTPException

from app.core.security import get_current_user

STUDENT, TEACHER, HOD, ADMIN = "student", "teacher", "hod", "admin"


def role_of(user) -> str:
    """Role as a lowercase string, whether the column holds a str or an Enum."""
    return str(getattr(user.role, "value", user.role)).lower()


def require_roles(*roles: str):
    """Dependency factory: `Depends(require_roles("hod", "admin"))`.
    Returns the current user if their role is allowed, otherwise 403."""
    allowed = {r.lower() for r in roles}

    def dependency(current_user=Depends(get_current_user)):
        if role_of(current_user) not in allowed:
            raise HTTPException(status_code=403, detail="You do not have permission for this action")
        return current_user

    return dependency


def can_request_swap(user, entry) -> bool:
    """Only a teacher who owns that timetable entry may request a swap for it."""
    return role_of(user) == TEACHER and entry.teacher.user_id == user.id


def can_decide_swap(user, swap) -> bool:
    """Admin, or the HOD of the SAME department as the requester.
    Nobody may decide a swap they requested themselves."""
    requester = swap.requester  # Teacher row of the person who asked
    if requester.user_id == user.id:
        return False
    role = role_of(user)
    if role == ADMIN:
        return True
    if role == HOD:
        # The department check is the easy one to forget.
        return user.department_id is not None and user.department_id == requester.department_id
    return False
