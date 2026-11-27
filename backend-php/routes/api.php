<?php

/*
| Every endpoint of the API. Laravel adds the /api prefix to everything here.
|
| One group per Python router, in alphabetical order (like backend/app/main.py),
| so two PRs adding routes don't touch the same lines. See CONVENTIONS.md.
|
|   Route::get('/rooms', [RoomController::class, 'index'])->middleware('jwt');            // any logged-in user
|   Route::post('/rooms', [RoomController::class, 'store'])->middleware('role:hod,admin'); // these roles only
*/

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SwapController;
use App\Http\Controllers\Api\TimetableController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

// --- auth (routers/auth.py) ---------------------------------------------------
Route::prefix('auth')->controller(AuthController::class)->group(function () {
    // throttle:auth (AppServiceProvider), over any limit = 429 + Retry-After:
    //   10 a minute per account per address
    //   30 a minute per account, from any address
    //  100 a minute per address, any accounts
    // login and activate share ONE budget: 6 logins + 4 activations for the
    // same account count as 10.
    Route::post('/login', 'login')->middleware('throttle:auth');
    Route::post('/activate', 'activate')->middleware('throttle:auth');
    Route::get('/me', 'me')->middleware('jwt');
    Route::post('/users', 'createUser')->middleware('role:admin');
});

// --- health -------------------------------------------------------------------
Route::get('/health', HealthController::class);

// --- rooms and reference data (routers/rooms.py) -----------------------------
Route::controller(RoomController::class)->group(function () {
    Route::get('/rooms', 'index')->middleware('jwt');
    Route::get('/rooms/empty', 'empty')->middleware('jwt');   // before /rooms/{roomId}
    Route::get('/rooms/{roomId}', 'show')->whereNumber('roomId')->middleware('jwt');
    Route::post('/rooms', 'store')->middleware('role:'.Roles::list(Roles::ADMINS));
    Route::put('/rooms/{roomId}', 'update')->whereNumber('roomId')->middleware('role:'.Roles::list(Roles::ADMINS));
    Route::get('/departments', 'departments')->middleware('jwt');
    Route::get('/courses', 'courses')->middleware('jwt');
});

// --- swaps (routers/swaps.py) ------------------------------------------------
Route::prefix('swap-requests')->controller(SwapController::class)->group(function () {
    Route::post('/', 'store')->middleware('role:'.Roles::TEACHER);
    Route::get('/', 'index')->middleware('role:'.Roles::list(Roles::STAFF));
    Route::post('/{swapId}/approve', 'approve')->whereNumber('swapId')->middleware('role:'.Roles::list(Roles::ADMINS));
    Route::post('/{swapId}/reject', 'reject')->whereNumber('swapId')->middleware('role:'.Roles::list(Roles::ADMINS));
    Route::post('/{swapId}/cancel', 'cancel')->whereNumber('swapId')->middleware('role:'.Roles::TEACHER);
});

// --- timetable (routers/timetable.py) ----------------------------------------
Route::get('/timetable', [TimetableController::class, 'index'])->middleware('jwt');
Route::post('/timetable/import', [TimetableController::class, 'import'])->middleware('role:'.Roles::list(Roles::ADMINS));
