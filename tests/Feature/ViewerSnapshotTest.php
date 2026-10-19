<?php

use App\Models\Pool;
use App\Models\User;
use App\Support\FrozenClock;
use Carbon\CarbonImmutable;

function snapshotFor(?User $user, int $poolId)
{
    return test()->getJson("/api/pools/{$poolId}", $user === null ? [] : ['X-Demo-User' => (string) $user->id])->assertOk();
}

/**
 * Pool of 100. Ana holds Temporary 6 (logged at the start) and has consumed 4; Ben holds Temporary 3 an hour later.
 *
 * @return array{0: Pool, 1: User, 2: User, 3: FrozenClock}
 */
function poolWithAnaAndBen(): array
{
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(100);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');

    submitAllocation($pool, $ana, 6, extra: ['type' => 'temporary'])->assertCreated();
    submitAllocation($pool, $ana, 4)->assertCreated();
    $clock->advance('1 hour');
    submitAllocation($pool, $ben, 3, extra: ['type' => 'temporary'])->assertCreated();

    return [$pool, $ana, $ben, $clock];
}

it('shows each operator only their own holdings, soonest first, and never consumed units', function () {
    [$pool, $ana, $ben, $clock] = poolWithAnaAndBen();
    submitAllocation($pool, $ana, 2, extra: ['type' => 'temporary'])->assertCreated();

    expect(snapshotFor($ana, $pool->id)->json('viewer.holdings'))->toBe([
        ['allocation_seq' => 2, 'units' => 6, 'expires_at' => '2026-10-20T10:00:00.000000Z'],
        ['allocation_seq' => 5, 'units' => 2, 'expires_at' => '2026-10-20T11:00:00.000000Z'],
    ])->and(snapshotFor($ben, $pool->id)->json('viewer.holdings'))->toBe([
        ['allocation_seq' => 4, 'units' => 3, 'expires_at' => '2026-10-20T11:00:00.000000Z'],
    ]);
});

it('names the viewer\'s own status and says nothing about anyone else\'s', function () {
    [$pool, $ana, $ben] = poolWithAnaAndBen();

    $anas = snapshotFor($ana, $pool->id);
    $bens = snapshotFor($ben, $pool->id);

    expect($anas->json('viewer.status'))->toBe('elevated')
        ->and($bens->json('viewer.status'))->toBe('standard')
        ->and($bens->getContent())->not->toContain('elevated');
});

it('gives a supervisor no status and no holdings', function () {
    [$pool] = poolWithAnaAndBen();
    $dana = User::factory()->supervisor()->create(['name' => 'Dana']);

    snapshotFor($dana, $pool->id)
        ->assertJsonPath('viewer', ['operator_id' => null, 'status' => null, 'tier' => null, 'window_seconds' => null, 'holdings' => []]);
});

it('reports the server time and the earliest future expiry, then none once everything has expired', function () {
    [$pool, $ana, , $clock] = poolWithAnaAndBen();

    $snapshot = snapshotFor($ana, $pool->id);

    expect($snapshot->json('server_time'))->toBe('2026-10-19T11:00:00.000000Z')
        ->and($snapshot->json('next_expiry_at'))->toBe('2026-10-20T10:00:00.000000Z');

    $clock->set(CarbonImmutable::parse('2026-10-20 10:30:00 UTC'));
    expect(snapshotFor($ana, $pool->id)->json('next_expiry_at'))->toBe('2026-10-20T11:00:00.000000Z');

    $clock->set(CarbonImmutable::parse('2026-10-20 11:00:00 UTC'));
    $later = snapshotFor($ana, $pool->id);

    expect($later->json('next_expiry_at'))->toBeNull()
        ->and($later->json('viewer.holdings'))->toBe([]);
});

it('brings the units back and drops the holding at the expiry instant', function () {
    [$pool, $ana, , $clock] = poolWithAnaAndBen();

    expect(snapshotFor($ana, $pool->id)->json('remaining'))->toBe(87);

    $clock->set(CarbonImmutable::parse('2026-10-20 09:59:59.999 UTC'));
    expect(snapshotFor($ana, $pool->id)->json('remaining'))->toBe(87);

    $clock->set(CarbonImmutable::parse('2026-10-20 10:00:00 UTC'));
    $snapshot = snapshotFor($ana, $pool->id);

    expect($snapshot->json('remaining'))->toBe(93)
        ->and($snapshot->json('viewer.holdings'))->toBe([])
        ->and($snapshot->json('viewer.status'))->toBe('standard');
});

it('gives an operator with no allocations the lowest status and no holdings', function () {
    [$pool] = poolWithAnaAndBen();
    $quiet = operatorNamed('Quiet');

    snapshotFor($quiet, $pool->id)
        ->assertJsonPath('viewer.status', 'standard')
        ->assertJsonPath('viewer.holdings', []);
});
