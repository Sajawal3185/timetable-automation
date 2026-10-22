"""Small adapter used by Wajiha's routers.

It depends only on get_current_user (Sajawal's security.py) and on user.role being
one of: student, teacher, hod, admin.

When Taha merges core/permissions.py, replace require_roles() below with his
version (one import change per router) so there is a single source of truth.
"""
from fastapi import Depends, HTTPException

from app.core.security import get_current_user

STAFF = ("teacher", "hod", "admin")
ADMINS = ("hod", "admin")


def require_roles(*roles: str):
    """Dependency factory: allow the request only if the user has one of `roles`."""
    def checker(user=Depends(get_current_user)):
        user_role = (getattr(user, "role", "") or "").lower()
        if user_role not in roles:
            raise HTTPException(status_code=403, detail=f"Requires one of these roles: {', '.join(roles)}")
        return user
    return checker