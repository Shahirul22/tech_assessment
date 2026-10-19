<?php

namespace App\Pool;

use App\Models\User;
use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\Expiries;
use App\Pool\Calculation\PoolState;
use App\Pool\Config\RulesConfig;
use App\Support\ApiTime;

/**
 * The per-viewer block of the snapshot and of the submit response: status, tier window and held temporary units.
 * One builder serves both, so the two can never disagree (DD6).
 */
class ViewerView
{
    public function __construct(
        private readonly RulesConfig $rules,
        private readonly Expiries $expiries,
    ) {}

    /**
     * @return array{operator_id: int|null, status: string|null, tier: string|null, window_seconds: int|null, holdings: list<array{allocation_seq: int, units: int, expires_at: string}>}
     */
    public function for(PoolState $state, ?User $viewer): array
    {
        if ($viewer?->role !== 'operator') {
            return ['operator_id' => null, 'status' => null, 'tier' => null, 'window_seconds' => null, 'holdings' => []];
        }

        return [
            'operator_id' => $viewer->id,
            'status' => $state->statuses[$viewer->id] ?? $this->rules->lowestStatus(),
            'tier' => $viewer->tier,
            'window_seconds' => $this->rules->windowSeconds($viewer->tier),
            'holdings' => $state->effective === null ? [] : array_map(
                fn (AllocationEntry $entry) => [
                    'allocation_seq' => $entry->seq,
                    'units' => $entry->units,
                    'expires_at' => ApiTime::format($entry->expiresAt),
                ],
                $this->expiries->holdings($state->effective, $viewer->id),
            ),
        ];
    }
}
