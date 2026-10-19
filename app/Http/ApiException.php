<?php

namespace App\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An API failure with a machine-readable code. Rendered as {"error": {"code", "message", "details"}}
 * by ApiErrorRenderer (plan §6 conventions).
 */
class ApiException extends HttpException
{
    /**
     * @param  string  $errorCode  snake_case, for example 'unknown_user'.
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        array $headers = [],
    ) {
        parent::__construct($status, $message, null, $headers);
    }
}
