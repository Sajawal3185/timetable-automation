<?php

use App\Exceptions\DetailErrors;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\RequireRoles;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 'jwt'           = must be logged in       (get_current_user)
        // 'role:hod,admin' = must have one of these roles (require_roles)
        $middleware->alias([
            'jwt' => AuthenticateJwt::class,
            'role' => RequireRoles::class,
        ]);

        // Input arrives exactly as sent, like FastAPI. Laravel would otherwise trim
        // every string (passwords too: "my pass " would be saved as "my pass") and
        // turn "" into null. Code that wants trimming does it itself (Identifiers::normalize).
        $middleware->remove([TrimStrings::class, ConvertEmptyStringsToNull::class]);

        // Render's proxy handles HTTPS in front of the app. Trust its X-Forwarded-*
        // headers so Laravel knows the real scheme (the /docs page calls https://)
        // and the real client IP (rate limits).
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error as {"detail": ...}, like FastAPI (app/Exceptions/DetailErrors.php).
        DetailErrors::register($exceptions);
    })->create();
