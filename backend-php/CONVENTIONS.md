# How we write the PHP backend

One way to do each thing. Copy these patterns instead of inventing your own.
The rule for every port: **the Python code and its tests are the specification.**
Same URLs, same JSON fields, same status codes, same error messages
(`docs/api-contract.md` is the source of truth).

## Getting started

**Once, on your laptop** (PHP 8.3+ and Composer; Laravel Herd installs both):

```
git switch main
git pull
cd backend-php
composer install
cp .env.example .env
php artisan key:generate
php artisan test
```

- With Herd's PHP 8.3, check that `php -m` lists `pdo_sqlite` and `mbstring`. If not,
  Herd left `php83\php.ini` without the standard extensions: ask Sajawal for the lines.
- In `.env`, set `JWT_SECRET_KEY` (needed by `php artisan serve`, not by the tests):
  `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
- Working on tables? Create a local database once:
  `New-Item database/database.sqlite` (PowerShell) or `touch database/database.sqlite`,
  then `php artisan migrate`. Start over any time with `php artisan migrate:fresh`.

**For every task, a new branch.** Never work on `main`:

```
git switch main
git pull
git switch -c php/conflict-checker        (a short name for your task)
```

**While you work:**

1. Write the code and port the Python tests (see the rest of this page).
2. `php artisan test` until everything is green.
3. Commit, `git push -u origin <your branch>`, and open a PR with the template
   filled in (under ~300 lines). CODEOWNERS asks the right reviewer.

**After someone else's PR is merged** (especially one that touches
`composer.json` or adds settings):

```
git switch main
git pull
cd backend-php
composer install
```

Then compare `.env.example` with your `.env` and copy over any new lines.
To bring the new `main` into a branch you're still working on:
`git switch <your branch>` then `git merge main`.

## Where files go

| What | Where | Make it with |
|---|---|---|
| Routes (every URL) | `routes/api.php` (Laravel adds `/api`) | edit the file |
| Controller (one per Python router) | `app/Http/Controllers/Api/RoomController.php` | `php artisan make:controller Api/RoomController` |
| Input checks (Pydantic request models) | `app/Http/Requests/StoreRoomRequest.php` | `php artisan make:request StoreRoomRequest` |
| JSON output (Pydantic `...Out` models) | `app/Http/Resources/RoomResource.php` | `php artisan make:resource RoomResource` |
| Logic (Python `services/`) | `app/Services/ConflictChecker.php` | `php artisan make:class Services/ConflictChecker` |
| Tables (Alembic) | `database/migrations/` | `php artisan make:migration create_rooms_table` |
| Models (SQLAlchemy) | `app/Models/Room.php` | `php artisan make:model Room` |
| Tests (pytest) | `tests/Feature/RoomsApiTest.php`, `tests/Unit/...` | `php artisan make:test RoomsApiTest` (add `--unit` for pure logic) |

Name files after the module (`Swap...`, `Room...`): `.github/CODEOWNERS` uses
the name to pick your reviewer.

## An endpoint, start to finish

The complete worked example is auth: `routes/api.php` (auth group),
`app/Http/Controllers/Api/AuthController.php`, `app/Http/Requests/LoginRequest.php`,
`app/Http/Resources/UserResource.php` and `tests/Feature/AuthApiTest.php`
(a port of `test_auth.py`). Open them side by side with `backend/app/routers/auth.py`.

```php
// routes/api.php: one group per Python router, alphabetical
Route::get('/rooms', [RoomController::class, 'index'])->middleware('jwt');
Route::post('/rooms', [RoomController::class, 'store'])->middleware('role:hod,admin');
```

```php
// app/Http/Controllers/Api/RoomController.php
public function store(StoreRoomRequest $request): JsonResponse
{
    // An exists() check alone isn't enough: two requests at the same moment both
    // pass it and the second crashes (500). Always catch the UNIQUE violation.
    // (An extra exists() first is fine when it saves work, as createUser does.)
    try {
        $room = Room::create($request->validated());
    } catch (UniqueConstraintViolationException) {    // Illuminate\Database\...
        abort(409, 'Room name already exists');        // exact Python message
    }

    return (new RoomResource($room))->response()->setStatusCode(201);
}
```

```php
// app/Http/Resources/RoomResource.php: list EVERY field the contract shows, nothing more
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'capacity' => $this->capacity,
        'created_at' => Fmt::dateTime($this->created_at),
    ];
}
```

Resources are not wrapped in `{"data": ...}` (turned off once in
`AppServiceProvider`), so a list is sent as a plain JSON list, like FastAPI.

## Who may call it

- `->middleware('jwt')`: any logged-in user (Python `Depends(get_current_user)`).
- `->middleware('role:hod,admin')`: these roles only (Python `require_roles("hod", "admin")`).
  It logs the user in too, so you don't need `jwt` as well.
  Groups: `'role:'.Roles::list(Roles::STAFF)` = teacher, hod, admin; `Roles::ADMINS` = hod, admin.
- The logged-in user: `$request->user()`. The role always comes from the
  database row, never from the request body or the token.
- **No role checks inside controllers.** Rules that need data (only the HOD
  of the same department, only the teacher who owns the entry) go in one
  service method, like `can_decide_swap` in Python, and the controller calls it.

## Input arrives exactly as sent

Laravel normally trims every string and turns `""` into `null`. That's
switched off (`bootstrap/app.php`) because FastAPI doesn't do it, and a
trimmed password would be a different password. Where Python calls
`.strip()` or `.lower()`, do the same yourself (`Identifiers::normalize()`
for identifiers and emails).

## Errors

Every error is `{"detail": ...}` (`app/Exceptions/DetailErrors.php` does it):

| You write | The client gets |
|---|---|
| `abort(404, 'Room not found')` | `404 {"detail": "Room not found"}` |
| `abort(403)` or a failed `role:` check | `403 {"detail": "You do not have permission for this action"}` |
| a Form Request rule fails | `422 {"detail": [{"type", "loc": ["body", "capacity"], "msg"}]}` |
| `throw new ApiException(409, ['message' => ..., 'conflicts' => [...]])` | `409 {"detail": {"message": ..., "conflicts": [...]}}` |
| an exception you didn't expect | `500 {"detail": "Internal Server Error"}` (the real error is in `storage/logs/laravel.log`) |

Copy messages **exactly** from the Python code, including capitals and full stops.

422 is the one exception: `loc` matches FastAPI, but `msg` is Laravel's
wording ("The capacity field must be an integer.") and `type` is always
`value_error`. That's fine because the frontend never shows a 422's `msg`
(`api.js` only displays `detail` when it's a string). Don't write frontend code
or tests that depend on 422 `msg` text.
Don't use `findOrFail()` for rows the user asked for: its 404 says "Not Found",
but Python says "Room not found". Use `Room::find($id) ?? abort(404, 'Room not found')`.

## Lists with paging

Exam papers and materials send `X-Total-Count`:

```php
[$papers, $total] = Paging::get($query->orderByDesc('year'), $request);   // ?skip=0&limit=50, max 200
return Paging::response(ExamPaperResource::collection($papers), $total);
```

## Dates and times

Always through `App\Support\Fmt`, so every module matches Python byte for byte:

| Column | Helper | Example |
|---|---|---|
| DATE | `Fmt::date()` | `"2026-11-21"` |
| TIME | `Fmt::time()` | `"09:00:00"`: Python sends seconds, even though the contract says HH:MM |
| DATETIME, `created_at` | `Fmt::dateTime()` | `"2026-11-21T09:05:00"` |

Days are full names (`"Monday"`).

## Database

- Same table and column names, types, lengths, `nullable`, `unique` and foreign keys as Alembic.
- Primary keys: `$table->id()`. Foreign keys: `$table->foreignId('room_id')->constrained('rooms')`,
  plus `->nullOnDelete()`, `->cascadeOnDelete()` or `->restrictOnDelete()` to match Python's `ondelete`.
- Tables with only `created_at`: `$table->dateTime('created_at')->useCurrent();` and
  `public const UPDATED_AT = null;` in the model.
- A row lock (`with_for_update` in Python) is `->lockForUpdate()` inside `DB::transaction(...)`.
- Before a PR that touches the database, run the tests against MySQL too:
  SQLite and MySQL compare text and dates a little differently.

## Tests

- Port every `test_*` case from the matching Python file: same input, same expected answer.
- Extend `Tests\TestCase`: each test gets an empty in-memory database.
- Log in with a real token: `$this->asRole('teacher')` (returns the user), or
  `$this->asUser($user)`. Then `$this->getJson(...)`, `$this->postJson(...)`.
- Test data: `User::factory()->hod()->create(['department_id' => $dept->id])`,
  `Department::factory()->create()`. Add a factory for each model you add.
- Run `php artisan test` before every push; CI runs it on every PR.

## Settings

Read with `config('timetable.jwt.secret')` etc. (`config/timetable.php`),
never `env()` outside `config/`. New settings go in `config/timetable.php`
**and** `.env.example`.

## Packages

Only Sajawal adds packages (`composer require ...`), so `composer.lock`
doesn't conflict in every PR. Already installed: `firebase/php-jwt`,
`phpoffice/phpspreadsheet`, `dedoc/scramble` (the `/docs` page),
`league/flysystem-aws-s3-v3` (Supabase storage). Need another one? Ask.

## Docs page

`/docs` (and `/openapi.json`) is built from the code by Scramble, like
FastAPI's. Type-hint Form Requests and return Resources, and your endpoint
shows up there with its fields. Add `@unauthenticated` to the doc comment of
public endpoints.
