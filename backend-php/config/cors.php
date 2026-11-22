<?php

/*
| CORS: the frontend (Netlify / Live Server) runs on another domain, so the
| browser blocks its API calls unless that domain is listed here.
|
| CORS_ORIGINS          comma-separated exact origins, same variable and
|                       default as the Python backend.
| CORS_ORIGIN_PATTERNS  optional comma-separated regexes, for Netlify deploy
|                       previews, e.g. #^https://deploy-preview-\d+--numl-archive\.netlify\.app$#
|
| X-Total-Count is exposed so the past papers / materials pages can read it
| (a browser hides every non-standard response header unless it's exposed).
*/

$list = fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value))));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $list((string) env('CORS_ORIGINS', 'http://localhost:5500,http://127.0.0.1:5500')),

    'allowed_origins_patterns' => $list((string) env('CORS_ORIGIN_PATTERNS', '')),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Total-Count'],

    'max_age' => 0,

    // Same as the Python backend (allow_credentials=True).
    'supports_credentials' => true,

];
