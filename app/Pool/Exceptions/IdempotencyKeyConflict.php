<?php

namespace App\Pool\Exceptions;

use RuntimeException;

/**
 * An idempotency key was sent again with a different operator, amount or type. Nothing is recorded.
 */
class IdempotencyKeyConflict extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("Idempotency key [{$idempotencyKey}] was already used for a different request.");
    }
}
