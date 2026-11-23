<?php

use App\Exceptions\ApiException;
use App\Exceptions\DetailErrors;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\RequireRoles;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error as {"detail": ...}, like FastAPI (app/Exceptions/DetailErrors.php).
        DetailErrors::register($exceptions);

        // An ApiException is an answer (a 409 swap clash), not a bug: no stack trace in the log.
        $exceptions->dontReport(ApiException::class);
    })->create();
