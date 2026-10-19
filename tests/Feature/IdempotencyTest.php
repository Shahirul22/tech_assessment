<?php

use App\Models\Allocation;
use Illuminate\Support\Facades\DB;

it('counts a request once however many times the same key and payload are replayed', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    $first = submitAllocation($pool, $ana, 3, 'key-1')
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    foreach (range(1, 9) as $ignored) {
        submitAllocation($pool, $ana, 3, 'key-1')
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertExactJson($first->json());
    }

    expect(Allocation::count())->toBe(1)
        ->and(Allocation::sole()->granted)->toBe(3)
        ->and($pool->fresh()->version)->toBe(2)
        ->and($first->json('pool.remaining'))->toBe(7);
});

it('returns the original result on a replay even after other requests changed the pool', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');

    $first = submitAllocation($pool, $ana, 3, 'key-1')->assertCreated();
    submitAllocation($pool, $ben, 5)->assertCreated();

    submitAllocation($pool, $ana, 3, 'key-1')
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($first->json());
});

it('returns the same none on a replay instead of trying again', function () {
    $pool = poolWithRemaining(2);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 2)->assertCreated();

    $none = submitAllocation($pool, $ben, 3, 'key-stale')
        ->assertCreated()
        ->assertJsonPath('allocation.outcome', 'none');

    // Capacity is added after the stale request. A replay must not become a fresh attempt.
    DB::table('restocks')->insert(['pool_id' => $pool->id, 'seq' => 4, 'amount' => 50, 'recorded_at' => now()]);
    DB::table('pools')->where('id', $pool->id)->update(['version' => 4]);

    submitAllocation($pool, $ben, 3, 'key-stale')
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('allocation.outcome', 'none')
        ->assertJsonPath('allocation.granted', 0)
        ->assertExactJson($none->json());

    expect(Allocation::where('operator_id', $ben->id)->count())->toBe(1);
});

it('refuses the same key with a different amount, operator or type and records nothing', function (string $change) {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 3, 'key-1')->assertCreated();
    $versionBefore = $pool->fresh()->version;

    $response = match ($change) {
        'amount' => submitAllocation($pool, $ana, 4, 'key-1'),
        'operator' => submitAllocation($pool, $ben, 3, 'key-1'),
        'type' => submitAllocation($pool, $ana, 3, 'key-1', ['type' => 'temporary']),
    };

    $response->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_conflict');

    expect(Allocation::count())->toBe(1)
        ->and(Allocation::sole()->granted)->toBe(3)
        ->and($pool->fresh()->version)->toBe($versionBefore);

    // The pool's remaining is unchanged: a fresh request still sees 7 left.
    submitAllocation($pool, $ana, 1)->assertCreated()->assertJsonPath('pool.remaining', 6);
})->with(['amount', 'operator', 'type']);

it('treats an omitted type and an explicit consumed as the same payload', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    $first = submitAllocation($pool, $ana, 3, 'key-1')->assertCreated();

    submitAllocation($pool, $ana, 3, 'key-1', ['type' => 'consumed'])
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($first->json());
});

it('scopes a key to its pool', function () {
    $poolA = poolWithRemaining(10);
    $poolB = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    submitAllocation($poolA, $ana, 3, 'shared-key')->assertCreated();
    submitAllocation($poolB, $ana, 3, 'shared-key')->assertCreated();

    expect(Allocation::count())->toBe(2);
});

it('replays a temporary grant after its window ended with the body of the first reply', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    $first = submitAllocation($pool, $ana, 4, 'key-temp', ['type' => 'temporary'])->assertCreated();

    $clock->advance('25 hours');

    $replay = submitAllocation($pool, $ana, 4, 'key-temp', ['type' => 'temporary'])
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json('allocation'))->toBe($first->json('allocation'))
        ->and($replay->json('pool.remaining'))->toBe(6)
        ->and($replay->json('pool'))->toBe($first->json('pool'))
        ->and(Allocation::count())->toBe(1);
});

it('refuses a temporary key replayed with a different amount, operator or type and records nothing', function (string $change) {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 3, 'key-temp', ['type' => 'temporary'])->assertCreated();

    $response = match ($change) {
        'amount' => submitAllocation($pool, $ana, 4, 'key-temp', ['type' => 'temporary']),
        'operator' => submitAllocation($pool, $ben, 3, 'key-temp', ['type' => 'temporary']),
        'type' => submitAllocation($pool, $ana, 3, 'key-temp', ['type' => 'consumed']),
    };

    $response->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_conflict');

    expect(Allocation::count())->toBe(1);
})->with(['amount', 'operator', 'type']);
