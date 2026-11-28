<?php

/*
|--------------------------------------------------------------------------
| Timetable settings (the PHP twin of backend/app/config.py)
|--------------------------------------------------------------------------
|
| Every setting the API needs, read from the environment with the SAME names
| as the Python backend, so Render keeps the same variables after cutover.
| Code reads them with config('timetable.jwt.secret') etc., never env().
|
| Database settings are not here: Laravel reads DB_URL / DB_SSL_CA in
| config/database.php.
*/

return [

    // --- Auth / JWT --------------------------------------------------------
    'jwt' => [
        // Required, no default: anyone who knows it can forge a token for any
        // user. AppServiceProvider refuses to serve requests without it.
        // At least 32 characters (Security::MIN_SECRET_BYTES). Always HS256:
        // the algorithm is fixed in Security, not read from JWT_ALGORITHM.
        'secret' => env('JWT_SECRET_KEY'),
        'expire_minutes' => (int) env('ACCESS_TOKEN_EXPIRE_MINUTES', 60),
    ],

    // --- API docs ------------------------------------------------------------
    // /docs and /openapi.json. Public, like FastAPI's: admins create staff
    // accounts there (there's no admin screen for it yet), so on by default,
    // production included. Fails closed: only true/1/yes/on turn it on; a
    // typo or "off"/"no" hides it. ((bool) "off" would have been true.)
    'docs_enabled' => filter_var(env('DOCS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // --- Email (signup and forgot-password codes, from #93) -----------------
    // "console": write the code to the log instead of sending it (local, CI).
    // "brevo":   send through Brevo's HTTPS API (Render blocks outgoing SMTP).
    // The password of every demo account (php artisan demo:seed / db:seed). 8+ characters.
    // Never written in the code: the deploy is public, so it would open the admin account.
    'seed_password' => env('SEED_PASSWORD'),

    'email' => [
        'provider' => env('EMAIL_PROVIDER', 'console'),
        'brevo_api_key' => env('BREVO_API_KEY'),
        'from' => env('EMAIL_FROM'),
        'from_name' => env('EMAIL_FROM_NAME', 'NUML Timetable'),
    ],

    // --- File uploads (exam papers, lecture materials) ----------------------
    // disk: a disk from config/filesystems.php. "local" = storage/app/private,
    // which is NOT kept across a Render redeploy (Supabase S3 later).
    // max_mb: keep it at or below the Dockerfile's upload_max_filesize (25M),
    // or PHP drops bigger files before the app can answer 413.
    'uploads' => [
        'disk' => env('UPLOAD_DISK', 'local'),
        'max_mb' => (int) env('MAX_UPLOAD_MB', 20),
    ],

];
