<?php

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\Expiries;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\Steps\ApplyExpiry;
use App\Pool\Config\RulesConfig;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

function expiriesEntry(int $seq, int $operatorId, int $units, string $type, CarbonImmutable $at, string $tier = 'bronze'): AllocationEntry
{
    return new AllocationEntry($seq, $seq, $operatorId, $type, $units, $tier, $at);
}

function effectiveLedger(array $allocations, CarbonImmutable $now): Ledger
{
    return (new ApplyExpiry)->apply(new Ledger([], $allocations), $now, app(RulesConfig::class));
}

$t0 = CarbonImmutable::parse('2026-10-19 10:00:00 UTC');

it('lists expired temporary entries in order of expiry, then seq, and never consumed or zero grants', function () use ($t0) {
    $ledger = effectiveLedger([
        expiriesEntry(5, 1, 2, 'temporary', $t0),
        expiriesEntry(2, 2, 3, 'temporary', $t0->addHour()),
        expiriesEntry(3, 1, 4, 'consumed', $t0),
        expiriesEntry(4, 2, 0, 'temporary', $t0),
        expiriesEntry(6, 3, 1, 'temporary', $t0),
    ], $t0->addHours(30));

    $expired = (new Expiries)->expired($ledger);

    expect(array_map(fn (AllocationEntry $e) => $e->seq, $expired))->toBe([5, 6, 2]);
});

it('does not list an entry that is still held', function () use ($t0) {
    $ledger = effectiveLedger([expiriesEntry(2, 1, 3, 'temporary', $t0)], $t0->addHours(24)->subMillisecond());

    expect((new Expiries)->expired($ledger))->toBe([]);
});

it('gives the earliest expiry among held entries, or null when none is held', function () use ($t0) {
    $expiries = new Expiries;
    $allocations = [
        expiriesEntry(2, 1, 3, 'temporary', $t0, 'gold'),
        expiriesEntry(3, 2, 3, 'temporary', $t0->addHours(2)),
        expiriesEntry(4, 2, 3, 'consumed', $t0),
    ];

    expect($expiries->nextDue(effectiveLedger($allocations, $t0))->equalTo($t0->addHours(26)))->toBeTrue()
        ->and($expiries->nextDue(effectiveLedger($allocations, $t0->addHours(100))))->toBeNull()
        ->and($expiries->nextDue(effectiveLedger([expiriesEntry(2, 1, 3, 'consumed', $t0)], $t0)))->toBeNull();
});

it('skips an already expired entry when finding the next expiry', function () use ($t0) {
    $ledger = effectiveLedger([
        expiriesEntry(2, 1, 3, 'temporary', $t0),
        expiriesEntry(3, 2, 3, 'temporary', $t0->addHours(10)),
    ], $t0->addHours(25));

    expect((new Expiries)->nextDue($ledger)->equalTo($t0->addHours(34)))->toBeTrue();
});

it('lists only the named operator\'s held temporary entries, soonest first', function () use ($t0) {
    $ledger = effectiveLedger([
        expiriesEntry(2, 1, 3, 'temporary', $t0->addHours(3)),
        expiriesEntry(3, 1, 2, 'temporary', $t0),
        expiriesEntry(4, 2, 5, 'temporary', $t0),
        expiriesEntry(5, 1, 4, 'consumed', $t0),
        expiriesEntry(6, 1, 0, 'temporary', $t0),
    ], $t0->addHours(1));

    $holdings = (new Expiries)->holdings($ledger, 1);

    expect(array_map(fn (AllocationEntry $e) => $e->seq, $holdings))->toBe([3, 2])
        ->and((new Expiries)->holdings($ledger, 9))->toBe([]);
});

it('breaks a tie in expiry by seq', function () use ($t0) {
    $ledger = effectiveLedger([
        expiriesEntry(8, 1, 1, 'temporary', $t0),
        expiriesEntry(7, 1, 1, 'temporary', $t0),
    ], $t0);

    expect(array_map(fn (AllocationEntry $e) => $e->seq, (new Expiries)->holdings($ledger, 1)))->toBe([7, 8]);
});
