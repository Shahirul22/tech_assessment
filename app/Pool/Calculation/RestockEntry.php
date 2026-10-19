<?php

namespace App\Pool\Calculation;

use Carbon\CarbonImmutable;

final readonly class RestockEntry
{
    /**
     * @param  ?CarbonImmutable  $at  When the restock was recorded; null where the time is not known (older fixtures).
     */
    public function __construct(
        public int $id,
        public int $seq,
        public int $amount,
        public ?CarbonImmutable $at = null,
    ) {}
}
