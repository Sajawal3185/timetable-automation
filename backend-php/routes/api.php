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
use Illuminate\Support\Facades\Route;

// --- auth (routers/auth.py) ---------------------------------------------------
Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('/login', 'login');
    Route::post('/activate', 'activate');
    Route::get('/me', 'me')->middleware('jwt');
    Route::post('/users', 'createUser')->middleware('role:admin');
});

// --- health -------------------------------------------------------------------
Route::get('/health', HealthController::class);
