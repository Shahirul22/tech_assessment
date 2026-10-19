<?php

namespace App\Pool\Calculation;

use Carbon\CarbonImmutable;

final readonly class CorrectionEntry
{
    /**
     * @param  string  $kind  'amend' or 'void'.
     * @param  int|null  $newAmount  The new granted units for an amend; null for a void.
     * @param  ?CarbonImmutable  $at  When the correction took effect; null where the time is not known (older fixtures).
     */
    public function __construct(
        public int $id,
        public int $seq,
        public int $allocationId,
        public string $kind,
        public ?int $newAmount,
        public ?CarbonImmutable $at = null,
    ) {}
}
