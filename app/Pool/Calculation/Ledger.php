<?php

namespace App\Pool\Calculation;

use Carbon\CarbonImmutable;

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

    /**
     * The ledger as it stood right after entry `$seq` was committed: only the entries numbered `$seq` or lower.
     */
    public function asOf(int $seq): self
    {
        $upTo = fn (RestockEntry|AllocationEntry|CorrectionEntry $entry): bool => $entry->seq <= $seq;

        return new self(
            array_values(array_filter($this->restocks, $upTo)),
            array_values(array_filter($this->allocations, $upTo)),
            array_values(array_filter($this->corrections, $upTo)),
        );
    }

    /**
     * The newest time among the entries numbered `$seq` or lower: `effectiveAt` for an allocation, `at` for a restock
     * or a correction. Entries without a time are skipped. Null when no entry has one.
     */
    public function instantAt(int $seq): ?CarbonImmutable
    {
        $latest = null;

        foreach ($this->asOf($seq)->entryTimes() as $time) {
            if ($latest === null || $time->greaterThan($latest)) {
                $latest = $time;
            }
        }

        return $latest;
    }

    /**
     * The instant a write is evaluated at (DD2): its receipt time, but never earlier than the newest entry already in
     * the ledger. A request received earlier can commit after one received later (the lock decides the order), and
     * evaluating it at its own earlier receipt time would make an expiry seem to come back. The instant never
     * decreases as the ledger grows, so each reply stays consistent with the ledger it was served from and a replay
     * reproduces it.
     */
    public function evaluationInstant(CarbonImmutable $receipt): CarbonImmutable
    {
        $newest = $this->instantAt(PHP_INT_MAX);

        return $newest !== null && $newest->greaterThan($receipt) ? $newest : $receipt;
    }

    /**
     * Where a moment sits in commit order: the highest seq of any stored entry whose time is at or before `$instant`,
     * or 0 when none is (M2 DD4). A derived entry, such as an expiry, is anchored to this number.
     */
    public function anchorAt(CarbonImmutable $instant): int
    {
        $anchor = 0;

        foreach ([...$this->restocks, ...$this->allocations, ...$this->corrections] as $entry) {
            $time = $entry instanceof AllocationEntry ? $entry->effectiveAt : $entry->at;

            if ($time !== null && $time->lessThanOrEqualTo($instant) && $entry->seq > $anchor) {
                $anchor = $entry->seq;
            }
        }

        return $anchor;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function entryTimes(): array
    {
        return array_values(array_filter([
            ...array_map(fn (AllocationEntry $entry) => $entry->effectiveAt, $this->allocations),
            ...array_map(fn (RestockEntry $entry) => $entry->at, $this->restocks),
            ...array_map(fn (CorrectionEntry $entry) => $entry->at, $this->corrections),
        ]));
    }
}
