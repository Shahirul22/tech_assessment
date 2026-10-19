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

function expiryCalculator(): PoolCalculator
{
    return new PoolCalculator(
        new FrozenClock(CarbonImmutable::parse('2026-01-01 00:00:00 UTC')),
        app(RulesConfig::class),
        new ApplyCorrections,
        new ApplyExpiry,
        new ComputeStatus,
    );
}

function heldEntry(int $seq, int $units, string $type, CarbonImmutable $at, string $tier = 'bronze', int $operatorId = 1): AllocationEntry
{
    return new AllocationEntry(
        id: $seq,
        seq: $seq,
        operatorId: $operatorId,
        type: $type,
        units: $units,
        tierAtLog: $tier,
        effectiveAt: $at,
    );
}

function remainingAt(Ledger $ledger, CarbonImmutable $at): int
{
    return expiryCalculator()->calculateAt($ledger, $at)->remaining;
}

it('returns the units of a temporary allocation at exactly expires_at', function () {
    $t = CarbonImmutable::parse('2026-10-19 10:00:00.000 UTC');
    $ledger = new Ledger([new RestockEntry(1, 1, 10)], [heldEntry(2, 4, 'temporary', $t)]);
    $expiresAt = $t->addHours(24);

    expect(remainingAt($ledger, $expiresAt->subMillisecond()))->toBe(6)
        ->and(remainingAt($ledger, $expiresAt))->toBe(10)
        ->and(remainingAt($ledger, $expiresAt->addMillisecond()))->toBe(10);
});

it('keeps the units of a temporary allocation held 1 ms before expires_at', function () {
    $t = CarbonImmutable::parse('2026-10-19 10:00:00.000 UTC');
    $ledger = new Ledger([new RestockEntry(1, 1, 10)], [heldEntry(2, 4, 'temporary', $t)]);

    $state = expiryCalculator()->calculateAt($ledger, $t->addHours(24)->subMillisecond());

    expect($state->remaining)->toBe(6)
        ->and($state->effective->allocations[0]->active)->toBeTrue()
        ->and($state->effective->allocations[0]->expiresAt->equalTo($t->addHours(24)))->toBeTrue();
});

it('never returns the units of a consumed allocation', function () {
    $t = CarbonImmutable::parse('2026-10-19 10:00:00 UTC');
    $ledger = new Ledger([new RestockEntry(1, 1, 10)], [heldEntry(2, 4, 'consumed', $t)]);

    $state = expiryCalculator()->calculateAt($ledger, $t->addHours(1000));

    expect($state->remaining)->toBe(6)
        ->and($state->effective->allocations[0]->expiresAt)->toBeNull();
});

it('holds only the granted units of a partial temporary grant', function () {
    $t = CarbonImmutable::parse('2026-10-19 10:00:00 UTC');
    $ledger = new Ledger([new RestockEntry(1, 1, 4)], [heldEntry(2, 4, 'temporary', $t)]);

    expect(remainingAt($ledger, $t))->toBe(0)
        ->and(remainingAt($ledger, $t->addHours(24)))->toBe(4);
});

it('holds nothing for a zero-unit temporary grant', function () {
    $t = CarbonImmutable::parse('2026-10-19 10:00:00 UTC');
    $ledger = new Ledger([new RestockEntry(1, 1, 10)], [heldEntry(2, 0, 'temporary', $t)]);

    $state = expiryCalculator()->calculateAt($ledger, $t);

    expect($state->remaining)->toBe(10)
        ->and($state->effective->allocations[0]->expiresAt)->toBeNull();
});

it('returns two entries that expire at the same instant together', function () {
    $t = CarbonImmutable::parse('2026-10-19 10:00:00 UTC');
    $ledger = new Ledger([new RestockEntry(1, 1, 10)], [
        heldEntry(2, 3, 'temporary', $t, operatorId: 1),
        heldEntry(3, 4, 'temporary', $t, operatorId: 2),
    ]);

    expect(remainingAt($ledger, $t->addHours(24)->subMillisecond()))->toBe(3)
        ->and(remainingAt($ledger, $t->addHours(24)))->toBe(10);
});

it('uses the tier recorded at log time for the window', function () {
    $t = CarbonImmutable::parse('2026-10-19 10:00:00 UTC');
    $ledger = new Ledger([new RestockEntry(1, 1, 10)], [heldEntry(2, 5, 'temporary', $t, tier: 'silver')]);

    expect(remainingAt($ledger, $t->addHours(48)->subMillisecond()))->toBe(5)
        ->and(remainingAt($ledger, $t->addHours(48)))->toBe(10);
});

it('counts 24 elapsed hours across a daylight-saving change', function () {
    $originalZone = date_default_timezone_get();
    date_default_timezone_set('Europe/Berlin');

    try {
        // Clocks go back at 03:00 on 2026-10-25, so that calendar day lasts 25 hours.
        $t = CarbonImmutable::parse('2026-10-24 12:00:00', 'Europe/Berlin');
        $ledger = new Ledger([new RestockEntry(1, 1, 10)], [heldEntry(2, 4, 'temporary', $t)]);
        $expiresAt = app(RulesConfig::class)->expiresAt('bronze', $t);

        expect($expiresAt->getTimestamp() - $t->getTimestamp())->toBe(86400)
            ->and(remainingAt($ledger, $expiresAt->subMillisecond()))->toBe(6)
            ->and(remainingAt($ledger, $expiresAt))->toBe(10);
    } finally {
        date_default_timezone_set($originalZone);
    }
});
