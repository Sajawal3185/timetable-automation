# API contract: notifications and swaps

Append to `docs/api-contract.md` (branch `docs/api-contract-notifications-swaps`).
All endpoints need `Authorization: Bearer <JWT>`. Times and days use the same format as the timetable endpoints.
Errors: `{"detail": "..."}` unless stated.

## Notifications

Every call is scoped to the logged-in user. An id that belongs to someone else returns 404.

| Method | URL | Purpose |
| --- | --- | --- |
| GET | `/api/notifications?unread_only=false&limit=50` | List my notifications, newest first (`limit` 1-200) |
| GET | `/api/notifications/unread-count` | Cheap count for the bell to poll |
| PATCH | `/api/notifications/{id}/read` | Mark one as read |
| POST | `/api/notifications/read-all` | Mark all of mine as read |

Notification object:
```json
{"id": 12, "type": "swap_approved", "message": "Swap request #4 was approved ...",
 "related_id": 4, "is_read": false, "created_at": "2026-10-22T09:30:00"}
```
`type`: `schedule_changed | swap_requested | swap_approved | swap_rejected | makeup_added`.

- `GET /unread-count` -> `{"count": 3}`
- `PATCH /{id}/read` -> the updated notification object. 404 if not found / not mine.
- `POST /read-all` -> `{"updated": 3}`

## Swap requests

A swap exchanges the day/start/end of two timetable entries. Each entry keeps its teacher, section and room.

Swap object:
```json
{"id": 4, "requester_teacher_id": 2, "entry_id": 10, "target_entry_id": 25,
 "status": "pending", "reason": "Conference", "decided_by": null,
 "decided_at": null, "decision_note": null, "created_at": "2026-10-22T09:00:00"}
```
`status`: `pending | approved | rejected | cancelled`.

| Method | URL | Who | Body |
| --- | --- | --- | --- |
| POST | `/api/swap-requests` | teacher who owns `entry_id` | `{"entry_id": 10, "target_entry_id": 25, "reason": "..."}` |
| GET | `/api/swap-requests` | teacher / HOD / admin | none |
| POST | `/api/swap-requests/{id}/approve` | HOD of requester's department, or admin | `{"note": "optional"}` |
| POST | `/api/swap-requests/{id}/reject` | same | `{"note": "required"}` |
| POST | `/api/swap-requests/{id}/cancel` | the requester | none |

- **POST create** -> 201, swap object plus `"warnings": [conflict, ...]` (soft conflicts only).
- **GET list**: teachers get their own requests; HOD gets pending requests of their department; admin gets all pending.
- **approve / reject / cancel** -> the updated swap object. Nobody can decide a swap they requested.

Conflict object:
```json
{"type": "room", "entry_id": 31, "detail": "room clash with entry 31 ...", "severity": "hard"}
```
`type`: `teacher | section | room`. `severity`: `hard | soft`.

Error codes:

| Code | Meaning |
| --- | --- |
| 400 | Swapping an entry with itself |
| 403 | Wrong role, not your entry, wrong department, or deciding your own request |
| 404 | Entry or swap request does not exist |
| 409 | Hard conflict (`{"detail": {"message": "...", "conflicts": [conflict, ...]}}`), a pending request already exists for one of the classes, or the request is already decided |

## Who is notified

- Swap requested / approved / rejected: both teachers plus the students of both sections.
- Makeup class added: the teacher plus the section's students.
