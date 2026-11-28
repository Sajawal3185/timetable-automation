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
use App\Http\Controllers\Api\LectureMaterialController;
use App\Http\Controllers\Api\MakeupController;
use App\Http\Controllers\Api\NotificationController;
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

// --- lecture materials (routers/portal.py) -----------------------------------
Route::prefix('lecture-materials')->controller(LectureMaterialController::class)->middleware('jwt')->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store')->middleware('role:teacher,hod,admin');
    Route::get('/by-course/{courseId}', 'byCourse')->whereNumber('courseId');   // before /{materialId}
    Route::get('/{materialId}', 'show')->whereNumber('materialId');
    Route::get('/{materialId}/download', 'download')->whereNumber('materialId');
    Route::delete('/{materialId}', 'destroy')->whereNumber('materialId');
});

// --- makeup classes (routers/makeup.py) --------------------------------------
Route::prefix('makeup')->controller(MakeupController::class)->group(function () {
    Route::post('/find-slots', 'findSlots')->middleware('role:'.Roles::list(Roles::STAFF));
    Route::post('/', 'store')->middleware('role:'.Roles::list(Roles::STAFF));
    Route::get('/', 'index')->middleware('jwt');
});

// --- notifications (routers/notifications.py) --------------------------------
Route::prefix('notifications')->controller(NotificationController::class)->middleware('jwt')->group(function () {
    Route::get('/', 'index');
    Route::get('/unread-count', 'unreadCount');
    Route::patch('/{notificationId}/read', 'markRead')->whereNumber('notificationId');
    Route::post('/read-all', 'markAllRead');
});

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
