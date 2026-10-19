<?php

use App\Models\Allocation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('grants a request in full and updates the remaining units', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    submitAllocation($pool, $ana, 4)
        ->assertCreated()
        ->assertJsonPath('allocation.outcome', 'full')
        ->assertJsonPath('allocation.requested', 4)
        ->assertJsonPath('allocation.granted', 4)
        ->assertJsonPath('allocation.operator', ['id' => $ana->id, 'name' => 'Ana'])
        ->assertJsonPath('pool.remaining', 6)
        ->assertJsonPath('pool.version', 2);

    expect(Allocation::count())->toBe(1);
});

it('rejects an amount that is not a whole number of 1 or more and stores nothing', function (mixed $requested) {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    submitAllocation($pool, $ana, $requested)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['requested']]]]);

    expect(Allocation::count())->toBe(0)
        ->and($pool->fresh()->version)->toBe(1);
})->with([
    'zero' => 0,
    'negative' => -1,
    'fractional' => 1.5,
    'not a number' => 'abc',
    'numeric string' => '3',
    'null' => null,
    'a list' => [[3]],
    'a boolean' => true,
    'beyond the database integer' => 2147483648,
]);

it('rejects an Idempotency-Key that is too long, and stores nothing', function () {
    $pool = poolWithRemaining(10);

    submitAllocation($pool, operatorNamed('Ana'), 1, str_repeat('k', 256))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['idempotency_key']]]]);

    expect(Allocation::count())->toBe(0);
});

it('accepts an Idempotency-Key of 255 characters', function () {
    submitAllocation(poolWithRemaining(10), operatorNamed('Ana'), 1, str_repeat('k', 255))->assertCreated();
});

it('rejects a missing requested field and an unknown type, and stores nothing', function (array $body) {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    submitAllocation($pool, $ana, $body['requested'] ?? null, extra: array_diff_key($body, ['requested' => 1]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect(Allocation::count())->toBe(0);
})->with([
    'missing requested' => [[]],
    'unknown type' => [['requested' => 3, 'type' => 'borrowed']],
    'type of the wrong kind' => [['requested' => 3, 'type' => 7]],
]);

it('stores every field the ledger needs, with the tier the operator had at the time', function () {
    $clock = freezeClock();
    $pool = poolWithRemaining(20);
    $ana = operatorNamed('Ana', 'bronze');

    $first = submitAllocation($pool, $ana, 2, 'key-before')->assertCreated();
    $ana->update(['tier' => 'gold']);
    $second = submitAllocation($pool, $ana, 3, 'key-after')->assertCreated();

    $stored = Allocation::orderBy('seq')->get();

    expect($stored)->toHaveCount(2)
        ->and($stored[0]->only(['pool_id', 'operator_id', 'type', 'requested', 'granted', 'tier_at_log', 'idempotency_key']))
        ->toBe(['pool_id' => $pool->id, 'operator_id' => $ana->id, 'type' => 'consumed', 'requested' => 2, 'granted' => 2, 'tier_at_log' => 'bronze', 'idempotency_key' => 'key-before'])
        ->and($stored[0]->effective_at)->not->toBeNull()
        ->and($stored[0]->recorded_at)->not->toBeNull()
        // The earlier row keeps bronze after her tier changed; the new one says gold.
        ->and($stored[1]->tier_at_log)->toBe('gold')
        ->and($first->json('allocation.tier_at_log'))->toBe('bronze')
        ->and($second->json('allocation.tier_at_log'))->toBe('gold');

    expect($clock->now()->toIso8601String())->toBe('2026-10-17T10:00:00+00:00');
});

it('uses the server\'s receipt time and ignores a time sent by the client', function () {
    $clock = freezeClock('2026-10-17 10:00:00.123456 UTC');
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    submitAllocation(
        $pool, $ana, 3,
        extra: ['effective_at' => '2020-01-01T00:00:00Z', 'recorded_at' => '2020-01-01T00:00:00Z'],
        // A device clock one hour ahead.
        headers: ['Date' => gmdate('D, d M Y H:i:s \G\M\T', $clock->now()->addHour()->getTimestamp())],
    )->assertCreated()
        ->assertJsonPath('allocation.effective_at', '2026-10-17T10:00:00.123456Z');

    $stored = Allocation::sole();

    expect($stored->effective_at->format('Y-m-d\TH:i:s.u'))->toBe('2026-10-17T10:00:00.123456');
});

it('stores a request without a type as consumed, and accepts temporary through the API', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    submitAllocation($pool, $ana, 1)->assertCreated()->assertJsonPath('allocation.type', 'consumed');
    submitAllocation($pool, $ana, 1, extra: ['type' => 'temporary'])->assertCreated()->assertJsonPath('allocation.type', 'temporary');

    expect(Allocation::orderBy('seq')->pluck('type')->all())->toBe(['consumed', 'temporary']);
});

it('serves requests in arrival order, whatever the Date header of each request says', function () {
    freezeClock();
    $pool = poolWithRemaining(5);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    $later = gmdate('D, d M Y H:i:s \G\M\T', time() + 3600);
    $earlier = gmdate('D, d M Y H:i:s \G\M\T', time() - 3600);

    // Ana's request arrives first but claims a later time; Ben's arrives second and claims an earlier one.
    $anaResult = submitAllocation($pool, $ana, 4, headers: ['Date' => $later])->assertCreated();
    $benResult = submitAllocation($pool, $ben, 4, headers: ['Date' => $earlier])->assertCreated();

    expect($anaResult->json('allocation.seq'))->toBeLessThan($benResult->json('allocation.seq'))
        ->and($anaResult->json('allocation.outcome'))->toBe('full')
        ->and($benResult->json('allocation.outcome'))->toBe('partial')
        ->and($benResult->json('allocation.granted'))->toBe(1);
});

it('gives each stored entry the next seq and keeps the pool version equal to it', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    foreach ([1, 2, 3] as $expectedSeq) {
        submitAllocation($pool, $ana, 1)->assertCreated()
            ->assertJsonPath('allocation.seq', $expectedSeq + 1)
            ->assertJsonPath('pool.version', $expectedSeq + 1);
    }

    expect($pool->fresh()->version)->toBe(4)
        ->and(Allocation::orderBy('seq')->pluck('seq')->all())->toBe([2, 3, 4]);
});

it('refuses a request without an Idempotency-Key, from a supervisor, for an unknown pool or without a user', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $dana = User::factory()->supervisor()->create(['name' => 'Dana']);

    // `postJson` helper sends a key by default; send none by posting directly.
    $this->postJson("/api/pools/{$pool->id}/allocations", ['requested' => 1], ['X-Demo-User' => $ana->id])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'missing_idempotency_key');

    submitAllocation($pool, $dana, 1)->assertStatus(403)->assertJsonPath('error.code', 'forbidden_role');
    submitAllocation((new Pool)->forceFill(['id' => 999999]), $ana, 1)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    submitAllocation($pool, null, 1)->assertStatus(401)->assertJsonPath('error.code', 'unknown_user');

    expect(DB::table('allocations')->count())->toBe(0);
});

it('shows expires_at for a temporary grant and null for consumed or a zero grant', function () {
    freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(4);

    submitAllocation($pool, operatorNamed('Ana'), 4, extra: ['type' => 'temporary'])
        ->assertCreated()
        ->assertJsonPath('allocation.expires_at', '2026-10-20T10:00:00.000000Z');

    // The pool is empty now: a temporary request is granted nothing and so holds nothing.
    submitAllocation($pool, operatorNamed('Ben'), 2, extra: ['type' => 'temporary'])
        ->assertCreated()
        ->assertJsonPath('allocation.granted', 0)
        ->assertJsonPath('allocation.expires_at', null);

    submitAllocation(poolWithRemaining(5), operatorNamed('Cora'), 2)
        ->assertCreated()
        ->assertJsonPath('allocation.expires_at', null);
});

it('returns the viewer block with the new holding and status, and the same block on a replay', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = poolWithRemaining(20);
    $ana = operatorNamed('Ana');

    $first = submitAllocation($pool, $ana, 10, 'key-viewer', ['type' => 'temporary'])
        ->assertCreated()
        ->assertJsonPath('viewer.operator_id', $ana->id)
        ->assertJsonPath('viewer.status', 'elevated')
        ->assertJsonPath('viewer.tier', 'bronze')
        ->assertJsonPath('viewer.window_seconds', 86400)
        ->assertJsonPath('viewer.holdings', [
            ['allocation_seq' => 2, 'units' => 10, 'expires_at' => '2026-10-20T10:00:00.000000Z'],
        ]);

    $clock->advance('25 hours');

    $replay = submitAllocation($pool, $ana, 10, 'key-viewer', ['type' => 'temporary'])->assertOk();

    expect($replay->json('viewer'))->toBe($first->json('viewer'));
});
