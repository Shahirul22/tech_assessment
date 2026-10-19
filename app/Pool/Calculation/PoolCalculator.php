<?php

namespace App\Pool\Calculation;

use App\Pool\Calculation\Steps\ApplyCorrections;
use App\Pool\Calculation\Steps\ApplyExpiry;
use App\Pool\Calculation\Steps\ComputeStatus;
use App\Pool\Config\RulesConfig;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Derives a pool's state from its ledger. Pure: no database, no hidden clock (DD5, DD12).
 *
 * Pipeline: corrections, then expiry, then the sum; statuses come from ComputeStatus.
 */
final class PoolCalculator
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RulesConfig $rules,
        private readonly ApplyCorrections $applyCorrections,
        private readonly ApplyExpiry $applyExpiry,
        private readonly ComputeStatus $computeStatus,
    ) {}

    public function calculate(Ledger $ledger): PoolState
    {
        return $this->calculateAt($ledger, $this->clock->now());
    }

    /**
     * The state of the pool as it stands at an explicit instant (NFR-M2.1).
     */
    public function calculateAt(Ledger $ledger, CarbonImmutable $at): PoolState
    {
        $effective = $this->applyExpiry->apply($this->applyCorrections->apply($ledger), $at, $this->rules);

        return new PoolState(
            remaining: $this->remaining($effective),
            version: $this->version($ledger),
            statuses: $this->computeStatus->compute($effective, $at, $this->rules),
            effective: $effective,
        );
    }

    /**
     * How many of the requested units can be granted: never more than remains, never negative.
     */
    public function grantFor(PoolState $state, int $requested): int
    {
        return max(0, min($requested, $state->remaining));
    }

    /**
     * Σ restocks − Σ consumed units − Σ temporary units that are still active.
     */
    private function remaining(Ledger $ledger): int
    {
        $held = 0;

        foreach ($ledger->allocations as $allocation) {
            if ($allocation->type === 'consumed' || $allocation->active) {
                $held += $allocation->units;
            }
        }

        return array_sum(array_map(fn (RestockEntry $restock) => $restock->amount, $ledger->restocks)) - $held;
    }

    /**
     * The highest seq in the ledger as it was loaded, whatever the steps do to it.
     */
    private function version(Ledger $ledger): int
    {
        return max([0, ...array_map(
            fn (RestockEntry|AllocationEntry|CorrectionEntry $entry) => $entry->seq,
            [...$ledger->restocks, ...$ledger->allocations, ...$ledger->corrections],
        )]);
    }
}
