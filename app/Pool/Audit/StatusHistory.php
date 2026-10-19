<?php

namespace App\Pool\Audit;

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Config\RulesConfig;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The corrected operator's status before and after one correction (M3 DD8, FR7, PQ2). Pure: no database.
 *
 * "Before" is the history with the corrections numbered below the correction, "after" with those up to and including
 * it; both apply to the whole history. Only the corrected operator is ever evaluated (FR7.1).
 */
final class StatusHistory
{
    public function __construct(
        private readonly PoolCalculator $calculator,
        private readonly RulesConfig $rules,
    ) {}

    /**
     * @param  string  $operatorName  Used in the point labels; the ledger holds no names.
     *
     * @throws InvalidArgumentException when the ledger has no such correction
     */
    public function forCorrection(Ledger $full, int $correctionSeq, CarbonImmutable $now, string $operatorName = 'the operator'): StatusChange
    {
        $correction = $this->correction($full, $correctionSeq);
        $corrected = $this->allocation($full, $correction->allocationId);
        $operatorId = $corrected->operatorId;

        $without = array_values(array_filter($full->corrections, fn (CorrectionEntry $c) => $c->seq < $correctionSeq));
        $with = array_values(array_filter($full->corrections, fn (CorrectionEntry $c) => $c->seq <= $correctionSeq));

        $point = function (string $label, ?int $seq, int $cutoff, CarbonImmutable $instant, CarbonImmutable $at) use ($full, $without, $with, $operatorId): array {
            return [
                'label' => $label,
                'seq' => $seq,
                'at' => $at,
                'before' => $this->statusAt($full, $without, $cutoff, $instant, $operatorId),
                'after' => $this->statusAt($full, $with, $cutoff, $instant, $operatorId),
            ];
        };

        $moment = $full->instantAt($correctionSeq) ?? $now;
        $first = $point('when corrected', $correctionSeq, $correctionSeq, $moment, $moment);

        $later = [];

        foreach ($full->allocations as $entry) {
            if ($entry->operatorId !== $operatorId) {
                continue;
            }

            if ($entry->seq >= $corrected->seq) {
                $instant = $full->instantAt($entry->seq) ?? $entry->effectiveAt;
                $later[] = [[$entry->seq, 0, 0], $point("after {$operatorName}'s request #{$entry->seq}", $entry->seq, $entry->seq, $instant, $entry->effectiveAt)];
            }

            if ($entry->type === 'temporary') {
                $expiresAt = $this->rules->expiresAt($entry->tierAtLog, $entry->effectiveAt);

                if ($expiresAt->lessThanOrEqualTo($now)) {
                    $anchor = $full->anchorAt($expiresAt);
                    $later[] = [[$anchor, 1, $entry->seq], $point("when {$operatorName}'s request #{$entry->seq} expired", null, $anchor, $expiresAt, $expiresAt)];
                }
            }
        }

        usort($later, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $points = [$first];

        foreach ([...array_column($later, 1), $point('now', null, PHP_INT_MAX, $now, $now)] as $candidate) {
            if ($candidate['before'] !== $candidate['after']) {
                $points[] = $candidate;
            }
        }

        return new StatusChange($first['before'], $first['after'], $points);
    }

    /**
     * @param  list<CorrectionEntry>  $corrections
     */
    private function statusAt(Ledger $full, array $corrections, int $cutoff, CarbonImmutable $instant, int $operatorId): string
    {
        $upTo = $full->asOf($cutoff);
        $state = $this->calculator->calculateAt(new Ledger($upTo->restocks, $upTo->allocations, $corrections), $instant);

        return $state->statuses[$operatorId] ?? $this->rules->lowestStatus();
    }

    private function correction(Ledger $full, int $seq): CorrectionEntry
    {
        foreach ($full->corrections as $correction) {
            if ($correction->seq === $seq) {
                return $correction;
            }
        }

        throw new InvalidArgumentException("No correction numbered {$seq} in the ledger.");
    }

    private function allocation(Ledger $full, int $id): AllocationEntry
    {
        foreach ($full->allocations as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        throw new InvalidArgumentException("The correction points at allocation {$id}, which is not in the ledger.");
    }
}
