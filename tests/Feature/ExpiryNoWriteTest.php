<?php

use Illuminate\Support\Facades\DB;

function ledgerCounts(int $poolId): array
{
    return [
        'restocks' => DB::table('restocks')->where('pool_id', $poolId)->count(),
        'allocations' => DB::table('allocations')->where('pool_id', $poolId)->count(),
        'corrections' => DB::table('corrections')->where('pool_id', $poolId)->count(),
        'version' => DB::table('pools')->where('id', $poolId)->value('version'),
    ];
}

it('writes nothing when a temporary grant expires, however often the pool is read', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    submitAllocation($pool, $ana, 4, extra: ['type' => 'temporary'])->assertCreated();

    $before = ledgerCounts($pool->id);

    $clock->advance('24 hours');
    $first = $this->getJson("/api/pools/{$pool->id}", ['X-Demo-User' => $ana->id])->assertOk();
    $second = $this->getJson("/api/pools/{$pool->id}", ['X-Demo-User' => $ana->id])->assertOk();

    expect($first->json('remaining'))->toBe(10)
        ->and($second->json('remaining'))->toBe(10)
        ->and(ledgerCounts($pool->id))->toBe($before);
});
