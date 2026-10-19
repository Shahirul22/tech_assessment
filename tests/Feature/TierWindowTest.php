<?php

function remainingAsSeenBy(int $poolId, int $userId): int
{
    return test()->getJson("/api/pools/{$poolId}", ['X-Demo-User' => $userId])->assertOk()->json('remaining');
}

it('takes the expiry window from tier_at_log after the operator\'s tier changes', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(10);
    $ben = operatorNamed('Ben', 'silver');
    $start = $clock->now();

    submitAllocation($pool, $ben, 5, extra: ['type' => 'temporary'])->assertCreated();
    expect($ben->fresh()->tier)->toBe('silver');

    $ben->update(['tier' => 'gold']);

    // Logged under silver: 48 hours, not the 72 of his new tier.
    $clock->set($start->addHours(48)->subMillisecond());
    expect(remainingAsSeenBy($pool->id, $ben->id))->toBe(5);

    $clock->set($start->addHours(48));
    expect(remainingAsSeenBy($pool->id, $ben->id))->toBe(10);

    // Logged now under gold: 72 hours.
    $second = $clock->now();
    submitAllocation($pool, $ben, 2, extra: ['type' => 'temporary'])->assertCreated()->assertJsonPath('pool.remaining', 8);

    $clock->set($second->addHours(72)->subMillisecond());
    expect(remainingAsSeenBy($pool->id, $ben->id))->toBe(8);

    $clock->set($second->addHours(72));
    expect(remainingAsSeenBy($pool->id, $ben->id))->toBe(10);
});
