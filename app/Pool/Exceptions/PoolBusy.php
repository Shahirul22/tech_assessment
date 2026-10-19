<?php

namespace App\Pool\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The pool's row lock could not be taken in time. Nothing is recorded; the client may retry.
 */
class PoolBusy extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The pool is busy. Try again.', 0, $previous);
    }
}
