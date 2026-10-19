<?php

namespace App\Pool\Audit;

use Carbon\CarbonImmutable;

/**
 * The pool as it stood at one place in the corrected history (M3 DD7).
 */
final readonly class HistoryPoint
{
    /**
     * @param  ?int  $seq  The stored entry this point follows; null for a derived expiry.
     * @param  int  $anchor  Where the point sits in commit order: its seq, or for an expiry the highest seq at or before it.
     * @param  CarbonImmutable  $at  When the entry happened or the units returned.
     * @param  string  $kind  'restock', 'allocation', 'correction' or 'expiry'.
     * @param  int  $remaining  The pool's remaining at this point, with every correction applied.
     * @param  ?int  $allocationSeq  For an expiry, the allocation whose units returned.
     */
    public function __construct(
        public ?int $seq,
        public int $anchor,
        public CarbonImmutable $at,
        public string $kind,
        public int $remaining,
        public ?int $allocationSeq = null,
    ) {}
}
