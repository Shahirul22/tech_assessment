<?php

use App\Models\Correction;
use App\Models\User;
use App\Pool\AddRestock;
use App\Pool\ApplyCorrection;
use App\Pool\CorrectionService;
use App\Pool\Exceptions\CorrectionRefused;
use App\Pool\Exceptions\IdempotencyKeyConflict;
use App\Pool\RestockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    freezeClock('2026-10-17 12:00:00 UTC');
});

function correctWithKey(array $world, string $key, int $allocationSeq, string $kind, ?int $amount, string $reason = 'Counted wrong', ?User $supervisor = null)
{
    return app(CorrectionService::class)->apply($world['pool'], $supervisor ?? $world['dana'], new ApplyCorrection($allocationSeq, $kind, $amount, $reason, $key));
}

it('replays a correction exactly after the entry was corrected again', function () {
    $world = workedPool();
    $first = correctWithKey($world, 'K', 2, 'amend', 20);

    // Another correction of the same entry, then the replay of the first.
    correctWithKey($world, 'other', 2, 'amend', 10);
    $replay = correctWithKey($world, 'K', 2, 'amend', 20);

    expect($replay->replayed)->toBeTrue()
        ->and($replay->correction->id)->toBe($first->correction->id)
        ->and($replay->correction->from_amount)->toBe(40)
        ->and($replay->pool->remaining)->toBe(20)
        ->and($replay->pool->version)->toBe($first->pool->version)
        ->and($replay->pool->statuses)->toEqual($first->pool->statuses)
        ->and(Correction::where('idempotency_key', 'K')->count())->toBe(1);
});

it('records one row however often the key is replayed', function () {
    $world = workedPool();
    correctWithKey($world, 'K', 2, 'amend', 20);

    foreach (range(1, 10) as $_) {
        expect(correctWithKey($world, 'K', 2, 'amend', 20)->replayed)->toBeTrue();
    }

    expect(Correction::count())->toBe(1)
        ->and($world['pool']->fresh()->version)->toBe(5);
});

it('refuses the same key with anything different and records nothing', function (callable $change) {
    $world = workedPool();
    correctWithKey($world, 'K', 2, 'amend', 20, 'Counted wrong');
    $eli = User::factory()->supervisor()->create(['name' => 'Eli']);

    expect(fn () => $change($world, $eli))->toThrow(IdempotencyKeyConflict::class);

    expect(Correction::count())->toBe(1)
        ->and($world['pool']->fresh()->version)->toBe(5);
})->with([
    'amount' => [fn ($world) => correctWithKey($world, 'K', 2, 'amend', 25)],
    'entry' => [fn ($world) => correctWithKey($world, 'K', 3, 'amend', 20)],
    'kind' => [fn ($world) => correctWithKey($world, 'K', 2, 'void', null)],
    'reason' => [fn ($world) => correctWithKey($world, 'K', 2, 'amend', 20, 'Another reason')],
    'supervisor' => [fn ($world, $eli) => correctWithKey($world, 'K', 2, 'amend', 20, 'Counted wrong', $eli)],
]);

it('stores nothing for a refused raise, so the same key is judged again after a restock', function () {
    $world = workedPool();

    expect(fn () => correctWithKey($world, 'K', 2, 'amend', 60))->toThrow(CorrectionRefused::class)
        // Still refused while nothing has changed.
        ->and(fn () => correctWithKey($world, 'K', 2, 'amend', 60))->toThrow(CorrectionRefused::class);

    app(RestockService::class)->add($world['pool'], $world['dana'], new AddRestock(50, null, (string) Str::uuid()));
    $result = correctWithKey($world, 'K', 2, 'amend', 60);

    expect($result->replayed)->toBeFalse()
        ->and($result->pool->remaining)->toBe(30);
});

it('replays a restock with the remaining it had originally', function () {
    $world = workedPool();
    $service = app(RestockService::class);
    $first = $service->add($world['pool'], $world['dana'], new AddRestock(50, 'Delivery', 'R'));
    correctWithKey($world, 'K', 2, 'amend', 20);

    $replay = $service->add($world['pool'], $world['dana'], new AddRestock(50, 'Delivery', 'R'));

    expect($replay->replayed)->toBeTrue()
        ->and($replay->pool->remaining)->toBe($first->pool->remaining)
        ->and($replay->pool->remaining)->toBe(50);
});

it('keeps a replay identical when the clock has moved past an expiry', function () {
    $world = expiryWorld(100);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHour());
    $first = correctWithKey($world, 'K', 2, 'amend', 2);

    // 25 hours after the entry was logged, its window has ended.
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHours(26));
    $replay = correctWithKey($world, 'K', 2, 'amend', 2);

    expect($first->pool->remaining)->toBe(98)
        ->and($replay->replayed)->toBeTrue()
        ->and($replay->pool->remaining)->toBe(98)
        ->and($replay->pool->version)->toBe($first->pool->version);
});
