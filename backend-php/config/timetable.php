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
        // firebase/php-jwt needs at least 32 bytes for HS256.
        'secret' => env('JWT_SECRET_KEY'),
        'algorithm' => env('JWT_ALGORITHM', 'HS256'),
        'expire_minutes' => (int) env('ACCESS_TOKEN_EXPIRE_MINUTES', 60),
    ],

    // --- File uploads -------------------------------------------------------
    'upload_dir' => env('UPLOAD_DIR', 'uploads'),
    'max_upload_mb' => (int) env('MAX_UPLOAD_MB', 20),

    // --- Email (signup and forgot-password codes, from #93) -----------------
    // "console": write the code to the log instead of sending it (local, CI).
    // "brevo":   send through Brevo's HTTPS API (Render blocks outgoing SMTP).
    'email' => [
        'provider' => env('EMAIL_PROVIDER', 'console'),
        'brevo_api_key' => env('BREVO_API_KEY'),
        'from' => env('EMAIL_FROM'),
        'from_name' => env('EMAIL_FROM_NAME', 'NUML Timetable'),
    ],

];
