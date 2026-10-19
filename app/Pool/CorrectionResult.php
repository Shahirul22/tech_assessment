<?php

namespace App\Pool;

use App\Models\Correction;
use App\Pool\Calculation\PoolState;

/**
 * The outcome of a correction: the stored entry and the pool as it stood right after it.
 */
final readonly class CorrectionResult
{
    /**
     * @param  bool  $replayed  True when an earlier request with the same idempotency key was returned instead of a new one.
     */
    public function __construct(
        public Correction $correction,
        public PoolState $pool,
        public bool $replayed,
    ) {}
}
