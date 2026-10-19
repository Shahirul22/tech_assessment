<?php

namespace App\Pool\Calculation;

/**
 * Everything the calculator needs from one pool's history. Immutable: each step returns a new ledger.
 */
final readonly class Ledger
{
    /**
     * @param  list<RestockEntry>  $restocks
     * @param  list<AllocationEntry>  $allocations
     * @param  list<CorrectionEntry>  $corrections
     */
    public function __construct(
        public array $restocks,
        public array $allocations,
        public array $corrections = [],
    ) {}
}
