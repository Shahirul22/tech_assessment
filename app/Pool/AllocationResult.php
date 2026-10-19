<?php

namespace App\Pool;

use App\Models\Allocation;
use App\Pool\Calculation\PoolState;

/**
 * The outcome of a submit: the stored allocation and the pool as it stood right after that allocation.
 */
final readonly class AllocationResult
{
    /**
     * @param  bool  $replayed  True when an earlier request with the same idempotency key was returned instead of a new one.
     */
    public function __construct(
        public Allocation $allocation,
        public PoolState $pool,
        public bool $replayed,
    ) {}
}
