<?php

use App\Models\User;
use App\Pool\AllocationService;
use App\Pool\ApplyCorrection;
use App\Pool\CorrectionService;
use App\Pool\SubmitAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function logAs(User $user, $pool, string $query = ''): array
{
    return test()->getJson("/api/pools/{$pool->id}/log{$query}", ['X-Demo-User' => (string) $user->id])->assertOk()->json();
}

function snapshotLogAs(User $user, $pool): array
{
    return test()->getJson("/api/pools/{$pool->id}", ['X-Demo-User' => (string) $user->id])->assertOk()->json('log');
}

function entryBySeq(array $log, int $seq): array
{
    return collect($log)->firstWhere('seq', $seq);
}

it('shows the correction as its own entry and the original allocation with what became of it', function () {
    freezeClock('2026-10-17 12:00:00 UTC');
    $world = workedPool();
    $result = correct($world, 2, 'amend', 20, 'Counted wrong');

    $log = snapshotLogAs($world['cara'], $world['pool']);

    expect($log[0])->toMatchArray([
        'seq' => 5, 'kind' => 'correction', 'allocation_seq' => 2, 'correction_kind' => 'amend', 'reason' => 'Counted wrong',
        'supervisor' => ['id' => $world['dana']->id, 'name' => 'Dana'],
        'operator' => ['id' => $world['ana']->id, 'name' => 'Ana'],
        'change' => ['from' => 40, 'to' => 20],
    ])->and(entryBySeq($log, 2))->toMatchArray([
        'kind' => 'allocation', 'requested' => 40, 'granted' => 40, 'outcome' => 'full', 'corrected_to' => 20, 'voided' => false,
    ])->and(entryBySeq($log, 3))->toMatchArray(['granted' => 50, 'corrected_to' => null, 'voided' => false])
        ->and($result->correction->seq)->toBe(5);
});

it('shows a void as voided with the original request and grant, and as "to" nothing', function () {
    freezeClock('2026-10-17 12:00:00 UTC');
    $world = workedPool();
    correct($world, 2, 'void', null, 'Duplicate');

    $log = snapshotLogAs($world['ana'], $world['pool']);

    expect($log[0])->toMatchArray(['kind' => 'correction', 'correction_kind' => 'void', 'change' => ['from' => 40, 'to' => null]])
        ->and(entryBySeq($log, 2))->toMatchArray(['requested' => 40, 'granted' => 40, 'corrected_to' => 0, 'voided' => true]);
});

it('derives the expiry entry from the corrected units', function () {
    $world = expiryWorld(100);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHour());
    expiryCorrect($world, 'amend', 2);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHours(30));

    $log = snapshotLogAs($world['ana'], $world['pool']);
    $expiry = collect($log)->firstWhere('kind', 'expiry');

    expect($expiry)->toMatchArray(['allocation_seq' => 2, 'units' => 2, 'operator' => ['id' => $world['ana']->id, 'name' => 'Ana']])
        ->and(entryBySeq($log, 2)['expires_at'])->toBe('2026-10-18T09:00:00.000000Z')
        ->and(entryBySeq($log, 2)['corrected_to'])->toBe(2);
});

it('leaves no expiry entry for a voided entry', function () {
    $world = expiryWorld(100);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHours(30));

    expect(collect(snapshotLogAs($world['ana'], $world['pool']))->where('kind', 'expiry'))->toHaveCount(1);

    expiryCorrect($world, 'void');

    $log = snapshotLogAs($world['ana'], $world['pool']);

    expect(collect($log)->where('kind', 'expiry'))->toHaveCount(0)
        ->and(entryBySeq($log, 2)['expires_at'])->toBeNull()
        ->and(entryBySeq($log, 2)['voided'])->toBeTrue();
});

it('gives an expiry entry to a zero-grant Temporary entry that was raised after its window', function () {
    $world = expiryWorld(4);
    $clock = $world['clock'];
    $clock->set(CarbonImmutable::parse(EXPIRY_T)->addMinutes(10));
    $ben = operatorNamed('Ben');
    $refused = app(AllocationService::class)->submit($world['pool'], $ben, new SubmitAllocation(3, 'temporary', (string) Str::uuid()));
    expect($refused->allocation->granted)->toBe(0);

    $clock->set(CarbonImmutable::parse(EXPIRY_T)->addHours(30));
    expect(collect(snapshotLogAs($ben, $world['pool']))->where('kind', 'expiry')->pluck('allocation_seq')->all())->toBe([2]);

    app(CorrectionService::class)->apply($world['pool'], $world['dana'], new ApplyCorrection(3, 'amend', 3, 'Was owed', (string) Str::uuid()));

    $expiries = collect(snapshotLogAs($ben, $world['pool']))->where('kind', 'expiry');

    expect($expiries->pluck('allocation_seq')->sort()->values()->all())->toBe([2, 3])
        ->and($expiries->firstWhere('allocation_seq', 3)['units'])->toBe(3);
});

it('pages by cursor and returns each entry once, corrections and expiries included', function () {
    $world = expiryWorld(100);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHour());
    expiryCorrect($world, 'amend', 3);
    expiryCorrect($world, 'amend', 2);
    $world['clock']->set(CarbonImmutable::parse(EXPIRY_T)->addHours(30));

    $everything = snapshotLogAs($world['ana'], $world['pool']);
    $seen = [];
    $page = logAs($world['ana'], $world['pool'], '?limit=2');
    $seen = [...$page['entries']];

    while ($page['has_more']) {
        $page = logAs($world['ana'], $world['pool'], '?limit=2&before='.end($seen)['cursor']);
        $seen = [...$seen, ...$page['entries']];
    }

    expect(array_column($seen, 'cursor'))->toBe(array_column($everything, 'cursor'))
        ->and(array_unique(array_column($seen, 'cursor')))->toHaveCount(count($seen));
});
