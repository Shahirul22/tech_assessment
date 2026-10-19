<?php

namespace App\Pool;

/**
 * What a supervisor asked for: the input to CorrectionService::apply().
 */
final readonly class ApplyCorrection
{
    /**
     * @param  int  $allocationSeq  The entry number shown in the log (the allocation's seq in this pool).
     * @param  string  $kind  'amend' or 'void'.
     * @param  ?int  $amount  The new granted units for an amend (1 or more); null for a void.
     */
    public function __construct(
        public int $allocationSeq,
        public string $kind,
        public ?int $amount,
        public string $reason,
        public string $idempotencyKey,
    ) {}
}
