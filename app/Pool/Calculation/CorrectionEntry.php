<?php

namespace App\Pool\Calculation;

final readonly class CorrectionEntry
{
    /**
     * @param  string  $kind  'amend' or 'void'.
     * @param  int|null  $newAmount  The new granted units for an amend; null for a void.
     */
    public function __construct(
        public int $id,
        public int $seq,
        public int $allocationId,
        public string $kind,
        public ?int $newAmount,
    ) {}
}
