<?php

namespace App\Pool\Calculation\Steps;

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
use App\Pool\Calculation\Ledger;

/**
 * Applies the supervisors' corrections to the allocations (M3 DD5, NFR-M3.1).
 *
 * Corrections are taken in ledger order (seq) and, for each allocation, the last one wins: an amend sets the granted
 * units, a void sets them to 0 and marks the entry voided. The entry keeps its time, tier and type, so ApplyExpiry
 * works out the same `expires_at` for the corrected units. A correction that names an unknown allocation is ignored.
 * Pure: the same ledger always gives the same list, whatever order the corrections arrive in.
 */
class ApplyCorrections
{
    public function apply(Ledger $ledger): Ledger
    {
        $lastByAllocation = [];

        $ordered = $ledger->corrections;
        usort($ordered, fn (CorrectionEntry $a, CorrectionEntry $b) => $a->seq <=> $b->seq);

        foreach ($ordered as $correction) {
            $lastByAllocation[$correction->allocationId] = $correction;
        }

        if ($lastByAllocation === []) {
            return $ledger;
        }

        return new Ledger(
            $ledger->restocks,
            array_map(fn (AllocationEntry $entry) => $this->corrected($entry, $lastByAllocation[$entry->id] ?? null), $ledger->allocations),
            $ledger->corrections,
        );
    }

    private function corrected(AllocationEntry $entry, ?CorrectionEntry $correction): AllocationEntry
    {
        if ($correction === null) {
            return $entry;
        }

        $voided = $correction->kind === 'void';
        $units = $voided ? 0 : (int) $correction->newAmount;

        return new AllocationEntry(
            id: $entry->id,
            seq: $entry->seq,
            operatorId: $entry->operatorId,
            type: $entry->type,
            units: $units,
            tierAtLog: $entry->tierAtLog,
            effectiveAt: $entry->effectiveAt,
            active: $entry->active,
            expiresAt: $entry->expiresAt,
            correctedFrom: $units === $entry->units && ! $voided ? $entry->correctedFrom : $entry->units,
            voided: $voided,
        );
    }
}
