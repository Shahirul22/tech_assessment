<?php

namespace App\Pool\Calculation\Steps;

use App\Pool\Calculation\Ledger;
use App\Pool\Config\RulesConfig;
use Carbon\CarbonImmutable;

/**
 * Names each operator's status from the units they hold (FR4).
 *
 * Held units are the operator's consumed units plus their temporary units that are still active. The ledger is the
 * one after ApplyExpiry, so a lapsed temporary entry no longer counts.
 */
class ComputeStatus
{
    /**
     * @return array<int, string> Status name by operator id. Operators with no allocations are absent.
     */
    public function compute(Ledger $ledger, CarbonImmutable $now, RulesConfig $rules): array
    {
        $held = [];

        foreach ($ledger->allocations as $entry) {
            $held[$entry->operatorId] ??= 0;

            if ($entry->type === 'consumed' || $entry->active) {
                $held[$entry->operatorId] += $entry->units;
            }
        }

        return array_map(fn (int $units) => $rules->statusFor($units), $held);
    }
}
