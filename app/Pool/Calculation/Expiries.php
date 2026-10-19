<?php

namespace App\Pool\Calculation;

use Carbon\CarbonImmutable;

/**
 * Reads expiry facts off an effective ledger, the output of ApplyExpiry. Pure.
 */
final class Expiries
{
    /**
     * Temporary entries that held units and have lapsed, in the order they lapsed.
     *
     * @return list<AllocationEntry>
     */
    public function expired(Ledger $ledger): array
    {
        $expired = array_values(array_filter(
            $ledger->allocations,
            fn (AllocationEntry $entry) => $this->isTemporaryWithUnits($entry) && ! $entry->active,
        ));

        usort($expired, fn (AllocationEntry $a, AllocationEntry $b) => $this->byExpiry($a, $b));

        return $expired;
    }

    /**
     * The earliest moment any still-held temporary entry returns its units, or null when none is held.
     */
    public function nextDue(Ledger $ledger): ?CarbonImmutable
    {
        $due = null;

        foreach ($ledger->allocations as $entry) {
            if ($this->isTemporaryWithUnits($entry) && $entry->active && ($due === null || $entry->expiresAt->lessThan($due))) {
                $due = $entry->expiresAt;
            }
        }

        return $due;
    }

    /**
     * One operator's temporary entries that are still held, soonest to return first.
     *
     * @return list<AllocationEntry>
     */
    public function holdings(Ledger $ledger, int $operatorId): array
    {
        $holdings = array_values(array_filter(
            $ledger->allocations,
            fn (AllocationEntry $entry) => $entry->operatorId === $operatorId
                && $this->isTemporaryWithUnits($entry)
                && $entry->active,
        ));

        usort($holdings, fn (AllocationEntry $a, AllocationEntry $b) => $this->byExpiry($a, $b));

        return $holdings;
    }

    private function isTemporaryWithUnits(AllocationEntry $entry): bool
    {
        return $entry->type === 'temporary' && $entry->units > 0 && $entry->expiresAt !== null;
    }

    private function byExpiry(AllocationEntry $a, AllocationEntry $b): int
    {
        return [$a->expiresAt->getTimestamp(), $a->expiresAt->micro, $a->seq]
            <=> [$b->expiresAt->getTimestamp(), $b->expiresAt->micro, $b->seq];
    }
}
