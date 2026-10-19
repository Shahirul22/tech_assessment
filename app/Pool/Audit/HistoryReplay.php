<?php

namespace App\Pool\Audit;

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Calculation\RestockEntry;
use App\Pool\Config\RulesConfig;
use Carbon\CarbonImmutable;

/**
 * Replays the corrected history to find where the pool dipped below 0 (M3 DD7, FR15). Pure: no database.
 *
 * Every stored entry, in commit order, and every expiry that has happened is a point. At each point the pool is worked
 * out from the entries up to it with ALL corrections applied, because a correction rewrites the past it points at.
 */
final class HistoryReplay
{
    public function __construct(
        private readonly PoolCalculator $calculator,
        private readonly RulesConfig $rules,
    ) {}

    /**
     * @return list<HistoryPoint> In history order: by position, a stored entry before an expiry anchored to it.
     */
    public function points(Ledger $full, CarbonImmutable $now): array
    {
        $points = [];

        foreach ($this->stored($full) as [$seq, $kind, $at]) {
            $instant = $full->instantAt($seq) ?? $now;
            $points[] = new HistoryPoint($seq, $seq, $at ?? $instant, $kind, $this->remainingAt($full, $seq, $instant));
        }

        foreach ($this->calculator->calculateAt($full, $now)->effective->allocations as $entry) {
            if ($entry->type !== 'temporary' || $entry->units <= 0) {
                continue;
            }

            $expiresAt = $this->rules->expiresAt($entry->tierAtLog, $entry->effectiveAt);

            if ($expiresAt->greaterThan($now)) {
                continue;
            }

            $anchor = $full->anchorAt($expiresAt);
            $points[] = new HistoryPoint(null, $anchor, $expiresAt, 'expiry', $this->remainingAt($full, $anchor, $expiresAt), $entry->seq);
        }

        usort($points, fn (HistoryPoint $a, HistoryPoint $b) => $this->position($a) <=> $this->position($b));

        return $points;
    }

    /**
     * The stretches where the corrected pool was below 0. A window opens at the first point below 0 and closes at the
     * first later point that is back at 0 or above.
     *
     * @param  list<HistoryPoint>  $points  From points().
     * @return list<HindsightWindow>
     */
    public function overdrawn(array $points, Ledger $full): array
    {
        $raises = $this->raises($full);
        $windows = [];
        $from = null;
        $lowest = 0;

        $close = function (?HistoryPoint $to) use (&$from, &$lowest, &$windows, $raises): void {
            $end = $to === null ? PHP_INT_MAX : $to->anchor;
            $seqs = array_keys(array_filter($raises, fn (int $allocationSeq) => $allocationSeq <= $end));
            $windows[] = new HindsightWindow($from, $to, $lowest, $seqs);
            $from = null;
        };

        foreach ($points as $point) {
            if ($from === null && $point->remaining < 0) {
                $from = $point;
                $lowest = $point->remaining;
            } elseif ($from !== null && $point->remaining < 0) {
                $lowest = min($lowest, $point->remaining);
            } elseif ($from !== null) {
                $close($point);
            }
        }

        if ($from !== null) {
            $close(null);
        }

        return $windows;
    }

    /**
     * The pool right after stored entry `$cutoff`, at `$instant`, with every correction of the full ledger applied.
     */
    private function remainingAt(Ledger $full, int $cutoff, CarbonImmutable $instant): int
    {
        $upTo = $full->asOf($cutoff);

        return $this->calculator->calculateAt(new Ledger($upTo->restocks, $upTo->allocations, $full->corrections), $instant)->remaining;
    }

    /**
     * @return list<array{0: int, 1: string, 2: ?CarbonImmutable}> Seq, kind and time of every stored entry, by seq.
     */
    private function stored(Ledger $full): array
    {
        $entries = [
            ...array_map(fn (RestockEntry $e) => [$e->seq, 'restock', $e->at], $full->restocks),
            ...array_map(fn (AllocationEntry $e) => [$e->seq, 'allocation', $e->effectiveAt], $full->allocations),
            ...array_map(fn (CorrectionEntry $e) => [$e->seq, 'correction', $e->at], $full->corrections),
        ];
        usort($entries, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $entries;
    }

    /**
     * The corrections that raise an entry above what it held right before, with the seq of the allocation they raise.
     *
     * @return array<int, int> Allocation seq by correction seq.
     */
    private function raises(Ledger $full): array
    {
        $held = [];
        $allocationSeq = [];

        foreach ($full->allocations as $entry) {
            $held[$entry->id] = $entry->units;
            $allocationSeq[$entry->id] = $entry->seq;
        }

        $corrections = $full->corrections;
        usort($corrections, fn (CorrectionEntry $a, CorrectionEntry $b) => $a->seq <=> $b->seq);

        $raises = [];

        foreach ($corrections as $correction) {
            if (! isset($held[$correction->allocationId])) {
                continue;
            }

            $to = $correction->kind === 'void' ? 0 : (int) $correction->newAmount;

            if ($to > $held[$correction->allocationId]) {
                $raises[$correction->seq] = $allocationSeq[$correction->allocationId];
            }

            $held[$correction->allocationId] = $to;
        }

        return $raises;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function position(HistoryPoint $point): array
    {
        return $point->kind === 'expiry' ? [$point->anchor, 1, (int) $point->allocationSeq] : [$point->anchor, 0, 0];
    }
}
