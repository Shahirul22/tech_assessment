<?php

use App\Models\Restock;
use App\Models\User;
use App\Pool\AddRestock;
use App\Pool\AllocationService;
use App\Pool\Exceptions\IdempotencyKeyConflict;
use App\Pool\RestockService;
use App\Pool\SubmitAllocation;
use Illuminate\Support\Str;

beforeEach(function () {
    freezeClock('2026-10-17 12:00:00 UTC');
});

function addRestock(array $world, int $amount = 50, ?string $note = 'Delivery', ?string $key = null)
{
    return app(RestockService::class)->add($world['pool'], $world['dana'], new AddRestock($amount, $note, $key ?? (string) Str::uuid()));
}

it('raises the remaining by exactly the restocked amount, and an operator can use it at once', function () {
    $world = workedPool();

    $result = addRestock($world);

    expect($result->pool->remaining)->toBe(50)
        ->and($result->replayed)->toBeFalse();

    $eve = operatorNamed('Eve');
    $granted = app(AllocationService::class)->submit($world['pool'], $eve, new SubmitAllocation(50, 'consumed', (string) Str::uuid()));

    expect($granted->allocation->granted)->toBe(50)
        ->and($granted->pool->remaining)->toBe(0);
});

it('stores the restock with the supervisor, amount, note and the next ledger number', function () {
    $world = workedPool();

    $result = addRestock($world, 50, 'Delivery');

    expect($result->restock->seq)->toBe(5)
        ->and($result->pool->version)->toBe(5)
        ->and($world['pool']->fresh()->version)->toBe(5)
        ->and($result->restock->actor_id)->toBe($world['dana']->id)
        ->and($result->restock->amount)->toBe(50)
        ->and($result->restock->note)->toBe('Delivery')
        ->and($result->restock->recorded_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-17 12:00:00.000000')
        ->and(Restock::where('pool_id', $world['pool']->id)->count())->toBe(2);
});

it('accepts a restock without a note', function () {
    $result = addRestock(workedPool(), 5, null);

    expect($result->restock->note)->toBeNull();
});

it('shares one gapless numbering with allocations', function () {
    $world = workedPool();
    $allocations = app(AllocationService::class);

    $first = addRestock($world, 10);
    $second = $allocations->submit($world['pool'], $world['ana'], new SubmitAllocation(3, 'consumed', (string) Str::uuid()));
    $third = addRestock($world, 10);

    expect([$first->restock->seq, $second->allocation->seq, $third->restock->seq])->toBe([5, 6, 7]);
});

it('replays a restock exactly, however often the key is sent', function () {
    $world = workedPool();
    $first = addRestock($world, 50, 'Delivery', 'key-1');

    // Other writes land in between: the replay still carries the pool as it stood after the restock.
    app(AllocationService::class)->submit($world['pool'], $world['ana'], new SubmitAllocation(20, 'consumed', (string) Str::uuid()));

    foreach (range(1, 5) as $_) {
        $replay = addRestock($world, 50, 'Delivery', 'key-1');

        expect($replay->replayed)->toBeTrue()
            ->and($replay->restock->id)->toBe($first->restock->id)
            ->and($replay->pool->remaining)->toBe(50)
            ->and($replay->pool->version)->toBe(5);
    }

    expect(Restock::where('pool_id', $world['pool']->id)->count())->toBe(2)
        ->and($world['pool']->fresh()->version)->toBe(6);
});

it('refuses the same key with a different amount, note or supervisor and records nothing', function (callable $change) {
    $world = workedPool();
    addRestock($world, 50, 'Delivery', 'key-1');
    $other = User::factory()->supervisor()->create(['name' => 'Eli']);

    expect(fn () => $change($world, $other))->toThrow(IdempotencyKeyConflict::class);

    expect(Restock::where('pool_id', $world['pool']->id)->count())->toBe(2)
        ->and($world['pool']->fresh()->version)->toBe(5);
})->with([
    'amount' => [fn ($world) => addRestock($world, 60, 'Delivery', 'key-1')],
    'note' => [fn ($world) => addRestock($world, 50, 'Other', 'key-1')],
    'no note' => [fn ($world) => addRestock($world, 50, null, 'key-1')],
    'supervisor' => [fn ($world, $other) => app(RestockService::class)->add($world['pool'], $other, new AddRestock(50, 'Delivery', 'key-1'))],
]);

it('restocks over HTTP as a supervisor and returns the new remaining', function () {
    $world = workedPool();

    $response = postRestock($world, $world['dana'], ['amount' => 50, 'note' => 'Delivery'])
        ->assertCreated()
        ->assertJsonPath('pool.remaining', 50)
        ->assertJsonPath('pool.version', 5)
        ->assertJsonPath('restock.seq', 5)
        ->assertJsonPath('restock.actor', ['id' => $world['dana']->id, 'name' => 'Dana'])
        ->assertJsonPath('restock.amount', 50)
        ->assertJsonPath('restock.note', 'Delivery');

    expect($response->json('restock.recorded_at'))->toBe('2026-10-17T12:00:00.000000Z')
        ->and($response->json('restock.id'))->toBeInt();

    // Operators see it in the log, and can use it at once.
    $snapshot = $this->getJson("/api/pools/{$world['pool']->id}", ['X-Demo-User' => (string) $world['ana']->id])->assertOk();
    expect($snapshot->json('remaining'))->toBe(50)
        ->and($snapshot->json('log.0'))->toMatchArray(['kind' => 'restock', 'amount' => 50, 'note' => 'Delivery']);
});

it('accepts a restock over HTTP without a note', function () {
    $world = workedPool();

    postRestock($world, $world['dana'], ['amount' => 5])->assertCreated()->assertJsonPath('restock.note', null);
});

it('refuses an operator and records nothing', function () {
    $world = workedPool();

    postRestock($world, $world['ana'], ['amount' => 50])->assertForbidden()->assertJsonPath('error.code', 'forbidden_role');

    expect(Restock::where('pool_id', $world['pool']->id)->count())->toBe(1)
        ->and($world['pool']->fresh()->version)->toBe(4);
});

it('rejects an amount that is not a whole number of at least 1 and records nothing', function (mixed $amount) {
    $world = workedPool();
    $body = $amount === 'missing' ? [] : ['amount' => $amount];

    postRestock($world, $world['dana'], $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['amount']]]]);

    expect(Restock::where('pool_id', $world['pool']->id)->count())->toBe(1);
})->with([0, -1, 1.5, '50', 'abc', null, 'missing', 2147483648, true]);

it('rejects a note that is not text or is too long', function (mixed $note) {
    $world = workedPool();

    postRestock($world, $world['dana'], ['amount' => 5, 'note' => $note])->assertStatus(422)->assertJsonPath('error.details.fields.note.0', fn ($m) => is_string($m));
})->with([12, [['x']], 'long' => fn () => str_repeat('a', 256)]);

it('requires an Idempotency-Key', function () {
    $world = workedPool();

    postRestock($world, $world['dana'], ['amount' => 5], withKey: false)->assertStatus(400)->assertJsonPath('error.code', 'missing_idempotency_key');
});

it('replays the same key with 200 and the original body, and refuses a different payload with 409', function () {
    $world = workedPool();
    $first = postRestock($world, $world['dana'], ['amount' => 50, 'note' => 'Delivery'], 'K')->assertCreated();

    $replay = postRestock($world, $world['dana'], ['amount' => 50, 'note' => 'Delivery'], 'K')
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json())->toBe($first->json());

    postRestock($world, $world['dana'], ['amount' => 60, 'note' => 'Delivery'], 'K')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_conflict');

    expect(Restock::where('pool_id', $world['pool']->id)->count())->toBe(2);
});

it('answers 404 for an unknown pool and 401 without a user', function () {
    $world = workedPool();

    $this->postJson('/api/pools/999999/restocks', ['amount' => 5], ['Idempotency-Key' => 'k', 'X-Demo-User' => (string) $world['dana']->id])->assertNotFound();
    postRestock($world, null, ['amount' => 5])->assertStatus(401)->assertJsonPath('error.code', 'unknown_user');
});
