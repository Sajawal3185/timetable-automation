<?php

namespace App\Exceptions;

use App\Support\Roles;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

/**
 * Every error answers {"detail": ...}, exactly like FastAPI, because the
 * frontend reads data.detail (frontend/js/api.js, timetable/tt-api.js).
 * Laravel's own shape ({"message", "errors"}) would break it.
 *
 *   abort(404, 'Room not found')     -> 404 {"detail": "Room not found"}
 *   abort(403) / role check fails    -> 403 {"detail": "You do not have permission for this action"}
 *   validation fails                 -> 422 {"detail": [{"type", "loc": ["body", "field"], "msg"}]}
 *   new ApiException(409, [...])     -> 409 {"detail": {...}}
 *   unknown URL / wrong method       -> 404 "Not Found" / 405 "Method Not Allowed"
 *   any other crash                  -> 500 "Internal Server Error" (details only in the log)
 *
 * Registered in bootstrap/app.php.
 */
class DetailErrors
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(fn (Throwable $e, Request $request) => self::render($e, $request));
    }

    public static function render(Throwable $e, Request $request): ?Response
    {
        if ($e instanceof HttpResponseException) {
            return null;   // already a finished response
        }

        if ($e instanceof ApiException) {
            return self::json($e->status, $e->detail, $e->headers);
        }

        if ($e instanceof ValidationException) {
            return self::json(422, self::validationDetail($e, $request));
        }

        if ($e instanceof AuthenticationException) {
            return self::json(401, 'Not authenticated', ['WWW-Authenticate' => 'Bearer']);
        }

        if ($e instanceof HttpExceptionInterface) {
            return self::json($e->getStatusCode(), self::httpDetail($e), $e->getHeaders());
        }

        // Anything else is a bug: log it (Laravel does), tell the client nothing.
        $detail = 'Internal Server Error';
        $response = self::json(500, $detail);
        if (config('app.debug') && app()->environment('local', 'testing')) {
            // Laptop only: needs APP_DEBUG=true AND APP_ENV=local, so a debug flag
            // left on in production (APP_ENV=production) still shows nothing.
            $response->setData(['detail' => $detail, 'debug' => [
                'exception' => $e::class, 'message' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
            ]]);
        }

        return $response;
    }

    private static function httpDetail(HttpExceptionInterface $e): string
    {
        $status = $e->getStatusCode();
        $message = $e->getMessage();

        // Messages Laravel writes itself are replaced by FastAPI's plain ones.
        $laravelMade = $message === ''
            || $e->getPrevious() instanceof ModelNotFoundException
            || str_starts_with($message, 'The route ')            // unknown URL
            || $message === 'This action is unauthorized.';        // Gate / authorize()

        if ($e instanceof MethodNotAllowedHttpException) {
            return 'Method Not Allowed';
        }
        if ($status === 403 && $laravelMade) {
            return Roles::DENIED;
        }
        if ($laravelMade) {
            return Response::$statusTexts[$status] ?? 'Error';
        }

        return $message;
    }

    /** FastAPI's 422 list: one {type, loc, msg} per failed rule. */
    private static function validationDetail(ValidationException $e, Request $request): array
    {
        // Where the bad value was: GET/DELETE read the query string, the rest the body.
        $where = in_array($request->method(), ['GET', 'HEAD', 'DELETE'], true) ? 'query' : 'body';

        $detail = [];
        foreach ($e->errors() as $field => $messages) {
            $loc = array_map(fn ($part) => ctype_digit($part) ? (int) $part : $part, explode('.', $field));
            foreach ($messages as $message) {
                $detail[] = ['type' => 'value_error', 'loc' => [$where, ...$loc], 'msg' => $message];
            }
        }

        return $detail;
    }

    private static function json(int $status, mixed $detail, array $headers = []): JsonResponse
    {
        return new JsonResponse(['detail' => $detail], $status, $headers);
    }
}
