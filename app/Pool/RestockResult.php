<?php

namespace App\Pool;

use App\Models\Restock;
use App\Pool\Calculation\PoolState;

/**
 * The outcome of a restock: the stored entry and the pool as it stood right after it.
 */
final readonly class RestockResult
{
    /**
     * @param  bool  $replayed  True when an earlier request with the same idempotency key was returned instead of a new one.
     */
    public function __construct(
        public Restock $restock,
        public PoolState $pool,
        public bool $replayed,
    ) {}
}
