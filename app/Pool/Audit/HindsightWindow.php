<?php

namespace App\Pool\Audit;

/**
 * A stretch of the corrected history in which the pool was below 0 ("overdrawn in hindsight", D11).
 */
final readonly class HindsightWindow
{
    /**
     * @param  HistoryPoint  $from  The first point below 0.
     * @param  ?HistoryPoint  $to  The first later point at or above 0 (a restock or an expiry); null if it never recovers.
     * @param  int  $lowest  The lowest remaining inside the window.
     * @param  list<int>  $correctionSeqs  The corrections that raise an entry at or before the window's end.
     */
    public function __construct(
        public HistoryPoint $from,
        public ?HistoryPoint $to,
        public int $lowest,
        public array $correctionSeqs,
    ) {}
}
