<?php

/*
| CORS: the frontend (Netlify / Live Server) runs on another domain, so the
| browser blocks its API calls unless that domain is listed here.
|
| CORS_ORIGINS          comma-separated exact origins, same variable and
|                       default as the Python backend.
| CORS_ORIGIN_PATTERNS  optional regexes for Netlify deploy previews, separated
|                       by SPACES (a regex may contain a comma: \d{1,3}), e.g.
|                       #^https://deploy-preview-\d+--numl-archive\.netlify\.app$#
|                       In a .env file wrap it in 'single quotes': unquoted, the
|                       leading # makes it a comment (empty), and in "double
|                       quotes" \d is an error that stops the app. Render's
|                       Environment tab takes it as-is, no quotes.
|
| X-Total-Count is exposed so the past papers / materials pages can read it
| (a browser hides every non-standard response header unless it's exposed).
*/

$list = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));
$patterns = fn (string $value): array => preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $list((string) env('CORS_ORIGINS', 'http://localhost:5500,http://127.0.0.1:5500')),

    'allowed_origins_patterns' => $patterns((string) env('CORS_ORIGIN_PATTERNS', '')),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Total-Count'],

    // Browsers may reuse a preflight answer for 10 minutes (Starlette's default),
    // instead of sending OPTIONS before every call that carries a token.
    'max_age' => 600,

    // Same as the Python backend (allow_credentials=True).
    'supports_credentials' => true,

];
