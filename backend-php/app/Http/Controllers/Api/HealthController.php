<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Is the server up? Public, and doesn't touch the database, so it answers
     * even when the database is slow. Render's health check and the
     * keep-awake monitor call it.
     *
     * @unauthenticated
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
