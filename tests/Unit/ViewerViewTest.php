<?php

use App\Models\User;
use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Calculation\RestockEntry;
use App\Pool\ViewerView;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function viewerUser(int $id, string $role = 'operator', ?string $tier = 'bronze'): User
{
    return (new User)->forceFill(['id' => $id, 'role' => $role, 'tier' => $role === 'operator' ? $tier : null]);
}

function viewerEntry(int $seq, int $operatorId, int $units, string $type, CarbonImmutable $at, string $tier = 'bronze'): AllocationEntry
{
    return new AllocationEntry($seq, $seq, $operatorId, $type, $units, $tier, $at);
}

function viewerState(array $allocations, CarbonImmutable $at)
{
    return app(PoolCalculator::class)->calculateAt(new Ledger([new RestockEntry(1, 1, 1000)], $allocations), $at);
}

$t0 = CarbonImmutable::parse('2026-10-19 10:00:00 UTC');

it('lists only the viewer\'s held temporary units, soonest first, with the expiry in ISO UTC', function () use ($t0) {
    $state = viewerState([
        viewerEntry(2, 1, 3, 'temporary', $t0->addHours(2)),
        viewerEntry(3, 1, 2, 'temporary', $t0),
        viewerEntry(4, 2, 7, 'temporary', $t0),
        viewerEntry(5, 1, 4, 'consumed', $t0),
    ], $t0->addHours(3));

    $view = app(ViewerView::class)->for($state, viewerUser(1));

    expect($view['holdings'])->toBe([
        ['allocation_seq' => 3, 'units' => 2, 'expires_at' => '2026-10-20T10:00:00.000000Z'],
        ['allocation_seq' => 2, 'units' => 3, 'expires_at' => '2026-10-20T12:00:00.000000Z'],
    ])->and($view['operator_id'])->toBe(1)
        ->and($view['tier'])->toBe('bronze')
        ->and($view['window_seconds'])->toBe(86400);
});

it('drops a holding once it has expired', function () use ($t0) {
    $state = viewerState([viewerEntry(2, 1, 3, 'temporary', $t0)], $t0->addHours(24));

    expect(app(ViewerView::class)->for($state, viewerUser(1))['holdings'])->toBe([]);
});

it('gives the lowest status and no holdings to an operator without allocations', function () use ($t0) {
    $view = app(ViewerView::class)->for(viewerState([], $t0), viewerUser(5));

    expect($view['status'])->toBe('standard')
        ->and($view['holdings'])->toBe([]);
});

it('names the viewer\'s own status and the window of their tier', function () use ($t0) {
    $state = viewerState([viewerEntry(2, 1, 12, 'consumed', $t0)], $t0);

    $view = app(ViewerView::class)->for($state, viewerUser(1, tier: 'gold'));

    expect($view['status'])->toBe('elevated')
        ->and($view['window_seconds'])->toBe(259200);
});

it('gives a supervisor or nobody null fields and no holdings', function () use ($t0) {
    $state = viewerState([viewerEntry(2, 1, 12, 'temporary', $t0)], $t0);
    $empty = ['operator_id' => null, 'status' => null, 'tier' => null, 'window_seconds' => null, 'holdings' => []];

    expect(app(ViewerView::class)->for($state, viewerUser(9, 'supervisor')))->toBe($empty)
        ->and(app(ViewerView::class)->for($state, null))->toBe($empty);
});
