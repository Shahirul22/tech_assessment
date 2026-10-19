<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns every failure on an /api/* route into the contract's error body. Anything else keeps Laravel's default.
 */
class ApiErrorRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        [$status, $code, $message, $details, $headers] = match (true) {
            $e instanceof ApiException => [$e->getStatusCode(), $e->errorCode, $e->getMessage(), $e->details, $e->getHeaders()],
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', ['fields' => $e->errors()], []],
            // Route model binding failures arrive here too: Laravel turns ModelNotFoundException into this before rendering.
            $e instanceof NotFoundHttpException => [404, 'not_found', 'Not found.', [], []],
            $e instanceof MethodNotAllowedHttpException => [405, 'method_not_allowed', 'Method not allowed.', [], $e->getHeaders()],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                Str::snake(Response::$statusTexts[$e->getStatusCode()] ?? 'error'),
                Response::$statusTexts[$e->getStatusCode()] ?? 'Error.',
                [],
                $e->getHeaders(),
            ],
            // The real exception is still reported and logged; the client gets no internals.
            default => [500, 'server_error', 'Server error.', [], []],
        };

        return response()->json(
            // An empty details must be a JSON object ({}), not a list ([]).
            ['error' => ['code' => $code, 'message' => $message, 'details' => $details === [] ? new \stdClass : $details]],
            $status,
            $headers,
        );
    }
}
