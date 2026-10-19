<?php

namespace App\Pool;

/**
 * What an operator asked for: the input to AllocationService::submit().
 */
final readonly class SubmitAllocation
{
    /**
     * @param  string  $type  'consumed' or 'temporary'.
     */
    public function __construct(
        public int $requested,
        public string $type,
        public string $idempotencyKey,
    ) {}
}
