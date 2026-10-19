<?php

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Calculation\RestockEntry;
use App\Pool\Calculation\Steps\ApplyCorrections;
use App\Pool\Calculation\Steps\ApplyExpiry;
use App\Pool\Calculation\Steps\ComputeStatus;
use App\Pool\Config\RulesConfig;
use App\Support\FrozenClock;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function statusCalculator(): PoolCalculator
{
    return new PoolCalculator(
        new FrozenClock(CarbonImmutable::parse('2026-01-01 00:00:00 UTC')),
        app(RulesConfig::class),
        new ApplyCorrections,
        new ApplyExpiry,
        new ComputeStatus,
    );
}

function statusEntry(int $seq, int $operatorId, int $units, string $type, CarbonImmutable $at): AllocationEntry
{
    return new AllocationEntry(
        id: $seq,
        seq: $seq,
        operatorId: $operatorId,
        type: $type,
        units: $units,
        tierAtLog: 'bronze',
        effectiveAt: $at,
    );
}

function statusesAt(array $allocations, CarbonImmutable $at): array
{
    return statusCalculator()->calculateAt(new Ledger([new RestockEntry(1, 1, 1000)], $allocations), $at)->statuses;
}

$t0 = CarbonImmutable::parse('2026-10-19 10:00:00 UTC');

it('moves an operator through the three status levels at the configured thresholds', function (int $held, string $status) use ($t0) {
    expect(statusesAt([statusEntry(2, 7, $held, 'consumed', $t0)], $t0)[7])->toBe($status);
})->with([
    'just below elevated' => [9, 'standard'],
    'at elevated' => [10, 'elevated'],
    'just below high' => [29, 'elevated'],
    'at high' => [30, 'high'],
]);

it('counts a temporary holding until the instant it expires', function () use ($t0) {
    $allocations = [statusEntry(2, 7, 12, 'temporary', $t0)];
    $expiresAt = $t0->addHours(24);

    expect(statusesAt($allocations, $expiresAt->subMillisecond())[7])->toBe('elevated')
        ->and(statusesAt($allocations, $expiresAt)[7])->toBe('standard');
});

it('keeps an operator elevated on consumed units alone', function () use ($t0) {
    expect(statusesAt([statusEntry(2, 7, 12, 'consumed', $t0)], $t0->addHours(500))[7])->toBe('elevated');
});

it('drops from high to elevated when part of the holding expires', function () use ($t0) {
    $allocations = [
        statusEntry(2, 7, 20, 'consumed', $t0),
        statusEntry(3, 7, 12, 'temporary', $t0),
    ];

    expect(statusesAt($allocations, $t0)[7])->toBe('high')
        ->and(statusesAt($allocations, $t0->addHours(24))[7])->toBe('elevated');
});

it('leaves an operator with no allocations out', function () use ($t0) {
    $statuses = statusesAt([statusEntry(2, 7, 5, 'consumed', $t0)], $t0);

    expect($statuses)->toHaveKey(7)->and($statuses)->not->toHaveKey(8);
});
