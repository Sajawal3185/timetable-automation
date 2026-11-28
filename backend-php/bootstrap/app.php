<?php

use App\Exceptions\ApiException;
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

        // Render's proxy handles HTTPS in front of the app and appends the caller's
        // address to X-Forwarded-For. Trusting it gives Laravel the real scheme (the
        // /docs page calls https://) and the real client IP (the auth rate limits).
        //
        // 'REMOTE_ADDR' trusts only the machine that connected to us, and on Render
        // that is always Render's proxy: the container has no public port.
        // $request->ip() is then the LAST X-Forwarded-For entry, the one Render
        // wrote. Entries a client sends itself come before it and are ignored
        // (AuthApiTest::test_rate_limit_address_is_the_one_render_appended).
        // Not '*': Laravel reads that as "trust every address", and ip() would be
        // the FIRST entry, which the client chooses (a fresh rate-limit key per try).
        // On Render that last entry turned out to be one of Cloudflare's/Render's own
        // addresses, so the rate limits use Cloudflare's CF-Connecting-IP instead
        // (App\Support\ClientIp); ip() is the fallback off Render.
        $middleware->trustProxies(at: 'REMOTE_ADDR');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error as {"detail": ...}, like FastAPI (app/Exceptions/DetailErrors.php).
        DetailErrors::register($exceptions);

        // An ApiException is an answer (a 409 swap clash), not a bug: no stack trace in the log.
        $exceptions->dontReport(ApiException::class);
    })->create();
