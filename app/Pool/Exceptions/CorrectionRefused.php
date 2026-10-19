<?php

namespace App\Pool\Exceptions;

use RuntimeException;

/**
 * A raise needs more units than are free right now (D7, FR6.2). Nothing is recorded.
 */
class CorrectionRefused extends RuntimeException
{
    public function __construct(
        public readonly int $needed,
        public readonly int $free,
        public readonly int $allocationSeq,
    ) {
        parent::__construct("Needs {$needed} more units, {$free} are free. Restock first.");
    }
}
