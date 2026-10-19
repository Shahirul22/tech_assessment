<?php

namespace App\Pool;

use App\Models\Pool;
use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\RestockEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loads one pool's history as the calculator's input (DD4, DD12).
 *
 * It reads inside the caller's transaction and takes no lock itself. This runs while the pool lock is held and reads the
 * whole ledger, so it reads plain rows with only the columns the calculator needs: hydrating an Eloquent model per row
 * (and casting each timestamp) made it the largest part of the time the lock was held, growing with the ledger.
 */
class LedgerRepository
{
    public function load(Pool $pool): Ledger
    {
        $poolId = $pool->getKey();

        return new Ledger(
            restocks: DB::table('restocks')->where('pool_id', $poolId)->orderBy('seq')
                ->get(['id', 'seq', 'amount'])
                ->map(fn ($row) => new RestockEntry((int) $row->id, (int) $row->seq, (int) $row->amount))
                ->all(),
            allocations: DB::table('allocations')->where('pool_id', $poolId)->orderBy('seq')
                ->get(['id', 'seq', 'operator_id', 'type', 'granted', 'tier_at_log', 'effective_at'])
                ->map(fn ($row) => new AllocationEntry(
                    id: (int) $row->id,
                    seq: (int) $row->seq,
                    operatorId: (int) $row->operator_id,
                    type: $row->type,
                    units: (int) $row->granted,
                    tierAtLog: $row->tier_at_log,
                    // timestamptz(6) comes back as text with its microseconds, for example 2026-10-17 11:00:00.123456+00.
                    effectiveAt: CarbonImmutable::parse($row->effective_at),
                ))
                ->all(),
            corrections: DB::table('corrections')->where('pool_id', $poolId)->orderBy('seq')
                ->get(['id', 'seq', 'allocation_id', 'kind', 'new_amount'])
                ->map(fn ($row) => new CorrectionEntry(
                    (int) $row->id,
                    (int) $row->seq,
                    (int) $row->allocation_id,
                    $row->kind,
                    $row->new_amount === null ? null : (int) $row->new_amount,
                ))
                ->all(),
        );
    }
}
