# Auth API (`/api/auth`)

The four endpoints in `AuthController`, with the same requests, answers and
error messages as `backend/app/routers/auth.py`. Every error is
`{"detail": ...}`. The live version, with a "Try it" button, is at `/docs`.

The user object (`UserOut`) is always exactly these fields, in this order:

```json
{"id": 5, "name": "Ali Khan", "role": "student", "identifier": "se-123456",
 "department_id": 1, "section_id": 3, "teacher_id": null}
```

`role` is `student`, `teacher`, `hod` or `admin`. `teacher_id` is the linked
teacher record (null for students and unlinked staff). The password hash is
never sent.

**Identifiers** are trimmed and lowercased before every lookup, exactly like
Python's `identifier.strip().lower()`: `" SE-123456 "` is `se-123456`.
**Passwords** are used exactly as typed (never trimmed), at least 8 characters
for new ones, at most 72 **bytes** (bcrypt's limit, so `é` counts twice).

## POST /api/auth/login

No token needed. One login for every role.

```json
{"identifier": "se-123456", "password": "my-password"}
```

`identifier` may also be the account's email.

| Status | Body |
|---|---|
| 200 | `{"token": "<JWT>", "user": {UserOut}}` |
| 401 | `{"detail": "Invalid credentials"}`: wrong password, unknown identifier, not activated yet, or disabled. Always the same message, on purpose. |
| 422 | `identifier` or `password` missing or empty |
| 429 | `{"detail": "Too Many Attempts."}` plus a `Retry-After` header: more than 10 tries a minute for one account from one address, 30 a minute for one account from any address, or 100 a minute from one address. **PHP only**: Python has no limit. |

Send the token on later calls as `Authorization: Bearer <JWT>`. It lasts
`ACCESS_TOKEN_EXPIRE_MINUTES` (60).

## POST /api/auth/activate

No token needed. A student on the roster sets their first password and is
logged in.

```json
{"identifier": "se-654321", "new_password": "at-least-8"}
```

| Status | Body |
|---|---|
| 200 | `{"token": "<JWT>", "user": {UserOut}}`, like login |
| 400 | `{"detail": "This account cannot be activated"}`: unknown identifier, not a student, already activated, or disabled. Same message for all, so it can't be used to check the roster. If two requests activate the same account at once, exactly one gets 200. |
| 422 | `new_password` shorter than 8 characters, or longer than 72 bytes (`"Password is too long (max 72 bytes)"`) |
| 429 | as for login |

## GET /api/auth/me

Needs a token (any role). The frontend calls it on page refresh.

| Status | Body |
|---|---|
| 200 | `{UserOut}` |
| 401 | `{"detail": "Not authenticated"}` (no token), `"Invalid or expired token"`, or `"User not found or inactive"`. Every 401 has a `WWW-Authenticate: Bearer` header. |

## POST /api/auth/users

Admins only. Creates a teacher, HOD or admin account. Students come from the
roster, never from here.

```json
{"name": "Dr. Sara", "identifier": "h-sara", "password": "at-least-8",
 "role": "hod", "department_id": 1}
```

`department_id` is optional, except that a HOD must have one.

| Status | Body |
|---|---|
| 201 | `{UserOut}` |
| 400 | `"role must be one of: admin, hod, teacher"`, `"department_id is required when role is hod"`, or `"Department not found"` |
| 401 | no token or bad token (as for `/me`) |
| 403 | `{"detail": "You do not have permission for this action"}`: logged in, but not an admin |
| 409 | `{"detail": "Identifier already exists"}` (after trimming and lowercasing), also when two requests create it at once |
| 422 | a field missing, `department_id` not a number, or the password rules above |

## Not ported

`POST /api/auth/register` was removed from Python (BE-04) and doesn't exist
here: it answers 404 or 405. Email-code signup arrives with #93.
