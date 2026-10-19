<?php

namespace App\Pool\Calculation;

final readonly class PoolState
{
    /**
     * @param  int  $remaining  Units still available.
     * @param  int  $version  The highest seq in the ledger.
     * @param  array<int, string>  $statuses  Status name by operator id.
     * @param  ?Ledger  $effective  The ledger after corrections and expiry, which the sum and statuses came from.
     */
    public function __construct(
        public int $remaining,
        public int $version,
        public array $statuses = [],
        public ?Ledger $effective = null,
    ) {}
}
