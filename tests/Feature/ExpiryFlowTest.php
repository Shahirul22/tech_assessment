<?php

use App\Models\Allocation;

it('holds a temporary grant until 24 hours after it was logged, then frees the units for the next request', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    $cora = operatorNamed('Cora');

    submitAllocation($pool, $ana, 4, extra: ['type' => 'temporary'])
        ->assertCreated()
        ->assertJsonPath('allocation.granted', 4)
        ->assertJsonPath('pool.remaining', 6);

    expect(Allocation::sole()->type)->toBe('temporary');

    submitAllocation($pool, $ben, 6)->assertCreated()->assertJsonPath('pool.remaining', 0);

    $clock->set($clock->now()->addHours(24)->subMillisecond());
    submitAllocation($pool, $cora, 3)
        ->assertCreated()
        ->assertJsonPath('allocation.granted', 0)
        ->assertJsonPath('allocation.outcome', 'none');

    $clock->advance('2 ms');
    submitAllocation($pool, $cora, 3)
        ->assertCreated()
        ->assertJsonPath('allocation.granted', 3)
        ->assertJsonPath('pool.remaining', 1);
});

it('returns the units of two temporary grants that expire at the same instant', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(6);

    submitAllocation($pool, operatorNamed('Ana'), 3, extra: ['type' => 'temporary'])->assertCreated();
    submitAllocation($pool, operatorNamed('Ben'), 3, extra: ['type' => 'temporary'])
        ->assertCreated()
        ->assertJsonPath('pool.remaining', 0);

    $clock->advance('24 hours');

    submitAllocation($pool, operatorNamed('Cora'), 6)
        ->assertCreated()
        ->assertJsonPath('allocation.granted', 6);
});

it('stores consumed when no type is sent', function () {
    $pool = poolWithRemaining(10);

    submitAllocation($pool, operatorNamed('Ana'), 2)->assertCreated();

    expect(Allocation::sole()->type)->toBe('consumed');
});

it('refuses an unknown type and stores nothing', function () {
    $pool = poolWithRemaining(10);

    submitAllocation($pool, operatorNamed('Ana'), 2, extra: ['type' => 'borrowed'])->assertStatus(422);

    expect(Allocation::count())->toBe(0)
        ->and($pool->fresh()->version)->toBe(1);
});

it('stores the granted units of a partial temporary grant and returns exactly those', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(4);

    submitAllocation($pool, operatorNamed('Ana'), 6, extra: ['type' => 'temporary'])
        ->assertCreated()
        ->assertJsonPath('allocation.requested', 6)
        ->assertJsonPath('allocation.granted', 4)
        ->assertJsonPath('allocation.outcome', 'partial')
        ->assertJsonPath('pool.remaining', 0);

    expect(Allocation::sole()->granted)->toBe(4);

    $clock->advance('24 hours');

    submitAllocation($pool, operatorNamed('Ben'), 4)->assertCreated()->assertJsonPath('allocation.granted', 4);
});
