<?php

namespace App\Pool\Calculation;

use Carbon\CarbonImmutable;

final readonly class AllocationEntry
{
    /**
     * @param  int  $units  Units granted, after corrections.
     * @param  bool  $active  False once the entry has expired and its units are back in the pool.
     * @param  string  $type  'consumed' or 'temporary'.
     * @param  string  $tierAtLog  The operator's tier when the entry was logged; it fixes the expiry window.
     * @param  ?CarbonImmutable  $expiresAt  When a temporary entry's units return; null until ApplyExpiry sets it.
     */
    public function __construct(
        public int $id,
        public int $seq,
        public int $operatorId,
        public string $type,
        public int $units,
        public string $tierAtLog,
        public CarbonImmutable $effectiveAt,
        public bool $active = true,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
