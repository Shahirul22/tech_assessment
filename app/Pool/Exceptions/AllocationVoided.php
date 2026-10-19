<?php

namespace App\Pool\Exceptions;

use RuntimeException;

/**
 * The entry was voided, which is final (PQ4, FR6.12). Nothing is recorded.
 */
class AllocationVoided extends RuntimeException
{
    public function __construct(public readonly int $allocationSeq)
    {
        parent::__construct("Entry #{$allocationSeq} was voided and cannot be corrected again.");
    }
}
