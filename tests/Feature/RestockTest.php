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
