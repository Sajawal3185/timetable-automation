"""The demo seed (scripts/seed.py): safe to repeat, and --reset really resets."""
from sqlalchemy import func, select

from app.core.security import verify_password
from app.models import makeup_class, notification, swap_request  # noqa: F401  (register tables)
from app.models.notification import Notification, NotificationType
from app.models.swap_request import SwapRequest
from app.models.teacher import Teacher
from app.models.timetable_entry import TimetableEntry
from app.models.user import User
from scripts import seed as seed_script

PASSWORD = "demo-password-1"


def _count(db, model):
    return db.execute(select(func.count()).select_from(model)).scalar_one()


def _user(db, identifier):
    return db.execute(select(User).where(User.identifier == identifier)).scalar_one()


def test_seed_creates_accounts_the_demo_can_use(db):
    seed_script.seed(db, PASSWORD)

    hod = _user(db, "hod-100301")
    assert hod.role == "hod" and hod.department_id is not None        # can approve its department's swaps
    teacher = _user(db, "tr-100201")
    linked = db.execute(select(Teacher).where(Teacher.user_id == teacher.id)).scalar_one()
    assert linked.department_id == hod.department_id                   # teacher login -> Teacher row, same department
    assert _user(db, "se-9244330").section_id is not None              # student sees a section's timetable
    assert verify_password(PASSWORD, teacher.password_hash)
    assert _user(db, "se-9244331").password_hash is None               # still to be activated
    assert _count(db, TimetableEntry) == len(seed_script.TIMETABLE)


def test_seed_twice_adds_nothing(db):
    seed_script.seed(db, PASSWORD)
    before = {model: _count(db, model) for model in (User, Teacher, TimetableEntry)}
    again = seed_script.seed(db, PASSWORD)
    assert again["added"] == 0 and again["timetable_entries"] == 0
    assert {model: _count(db, model) for model in before} == before


def test_reset_puts_the_demo_back(db):
    seed_script.seed(db, PASSWORD)

    # What one demo run leaves behind: an approved swap moved two classes, a
    # notification was sent, and the roster student chose a password.
    a, b = db.execute(select(TimetableEntry).order_by(TimetableEntry.id).limit(2)).scalars().all()
    a.start_time, b.start_time = b.start_time, a.start_time
    a.end_time, b.end_time = b.end_time, a.end_time
    teacher = db.execute(select(Teacher).where(Teacher.id == a.teacher_id)).scalar_one()
    db.add(SwapRequest(requester_teacher_id=teacher.id, entry_id=a.id, target_entry_id=b.id))
    db.add(Notification(user_id=teacher.user_id, type=NotificationType.swap_approved, message="x"))
    _user(db, "se-9244331").password_hash = "claimed"
    db.commit()

    done = seed_script.seed(db, PASSWORD, reset=True)

    assert done["swaps_deleted"] == 1 and done["notifications_deleted"] == 1
    assert _count(db, SwapRequest) == 0 and _count(db, Notification) == 0
    assert _user(db, "se-9244331").password_hash is None
    slots = {(e.day_of_week, e.start_time.strftime("%H:%M"), e.end_time.strftime("%H:%M"))
             for e in db.execute(select(TimetableEntry)).scalars()}
    assert slots == {(day, start, end) for *_, day, start, end in seed_script.TIMETABLE}
    assert _count(db, TimetableEntry) == len(seed_script.TIMETABLE)


def test_short_password_is_refused(db):
    try:
        seed_script.seed(db, "short")
    except ValueError:
        return
    raise AssertionError("a 5-character seed password was accepted")
