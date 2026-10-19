<?php

namespace App\Pool\Calculation\Steps;

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Config\RulesConfig;
use Carbon\CarbonImmutable;

/**
 * Marks temporary allocations whose window has ended as inactive, so their units count as free again.
 *
 * The window comes from the tier recorded when the entry was logged, never the operator's current tier (FR5.5).
 * An entry is held while now < expiresAt; at exactly expiresAt the units are back (FR5.3).
 */
class ApplyExpiry
{
    public function apply(Ledger $ledger, CarbonImmutable $now, RulesConfig $rules): Ledger
    {
        return new Ledger(
            $ledger->restocks,
            array_map(fn (AllocationEntry $entry) => $this->withExpiry($entry, $now, $rules), $ledger->allocations),
            $ledger->corrections,
        );
    }

    private function withExpiry(AllocationEntry $entry, CarbonImmutable $now, RulesConfig $rules): AllocationEntry
    {
        if ($entry->type !== 'temporary' || $entry->units <= 0) {
            return $entry;
        }

        $expiresAt = $rules->expiresAt($entry->tierAtLog, $entry->effectiveAt);

        return new AllocationEntry(
            id: $entry->id,
            seq: $entry->seq,
            operatorId: $entry->operatorId,
            type: $entry->type,
            units: $entry->units,
            tierAtLog: $entry->tierAtLog,
            effectiveAt: $entry->effectiveAt,
            active: $now->lessThan($expiresAt),
            expiresAt: $expiresAt,
        );
    }
}
