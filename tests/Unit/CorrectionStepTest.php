<?php

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
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

const CS_T = '2026-10-17 09:00:00.000000 UTC';

function csCalculator(): PoolCalculator
{
    return new PoolCalculator(
        new FrozenClock(CarbonImmutable::parse(CS_T)),
        app(RulesConfig::class),
        new ApplyCorrections,
        new ApplyExpiry,
        new ComputeStatus,
    );
}

function csEntry(int $seq, int $units, int $operatorId, string $type = 'consumed', string $tier = 'bronze', string $at = CS_T): AllocationEntry
{
    return new AllocationEntry($seq, $seq, $operatorId, $type, $units, $tier, CarbonImmutable::parse($at));
}

/** The §4 pool: capacity 100, Ana 40, Ben 50, Cara 10, nothing left. */
function csWorkedLedger(array $corrections = []): Ledger
{
    return new Ledger(
        [new RestockEntry(1, 1, 100)],
        [csEntry(2, 40, 1, tier: 'bronze'), csEntry(3, 50, 2, tier: 'silver'), csEntry(4, 10, 3, tier: 'gold')],
        $corrections,
    );
}

function csCorrection(int $seq, int $allocationId, string $kind, ?int $to): CorrectionEntry
{
    return new CorrectionEntry($seq, $seq, $allocationId, $kind, $to);
}

function csUnits(Ledger $ledger): array
{
    return collect($ledger->allocations)->mapWithKeys(fn (AllocationEntry $a) => [$a->seq => $a->units])->all();
}

it('frees units when a correction lowers an allocation', function () {
    $at = CarbonImmutable::parse(CS_T)->addHour();
    $before = csCalculator()->calculateAt(csWorkedLedger(), $at);
    $after = csCalculator()->calculateAt(csWorkedLedger([csCorrection(5, 2, 'amend', 20)]), $at);

    expect($before->remaining)->toBe(0)
        ->and($after->remaining)->toBe(20)
        ->and(csUnits($after->effective))->toBe([2 => 20, 3 => 50, 4 => 10])
        ->and($after->effective->allocations[0]->correctedFrom)->toBe(40);
});

it('frees the units of a voided allocation', function () {
    $state = csCalculator()->calculateAt(csWorkedLedger([csCorrection(5, 2, 'void', null)]), CarbonImmutable::parse(CS_T)->addHour());

    expect($state->remaining)->toBe(40)
        ->and($state->effective->allocations[0]->units)->toBe(0)
        ->and($state->effective->allocations[0]->voided)->toBeTrue()
        ->and($state->effective->allocations[0]->correctedFrom)->toBe(40);
});

it('changes history only when an expired allocation is corrected', function () {
    $ledger = fn (array $corrections) => new Ledger(
        [new RestockEntry(1, 1, 100)],
        [csEntry(2, 4, 1, 'temporary')],
        $corrections,
    );
    $afterExpiry = CarbonImmutable::parse(CS_T)->addHours(30);

    $plain = csCalculator()->calculateAt($ledger([]), $afterExpiry);
    $lowered = csCalculator()->calculateAt($ledger([csCorrection(3, 2, 'amend', 2)]), $afterExpiry);

    // Today's remaining and the entry's expired state do not move; only the units recorded in history do.
    expect($plain->remaining)->toBe(100)
        ->and($lowered->remaining)->toBe(100)
        ->and($lowered->effective->allocations[0]->active)->toBeFalse()
        ->and($lowered->effective->allocations[0]->units)->toBe(2)
        ->and($lowered->effective->allocations[0]->correctedFrom)->toBe(4);

    // The corrected history, read at a moment inside the window, holds the corrected units.
    expect(csCalculator()->calculateAt($ledger([csCorrection(3, 2, 'amend', 2)]), CarbonImmutable::parse(CS_T)->addHours(10))->remaining)->toBe(98);
});

it('leaves an expired Temporary raise without effect on the current remaining', function () {
    $ledger = new Ledger([new RestockEntry(1, 1, 100)], [csEntry(2, 4, 1, 'temporary')], [csCorrection(3, 2, 'amend', 9)]);

    $state = csCalculator()->calculateAt($ledger, CarbonImmutable::parse(CS_T)->addHours(30));

    expect($state->remaining)->toBe(100)
        ->and($state->effective->allocations[0]->units)->toBe(9);
});

it('lets the last correction of an allocation win', function () {
    $ledger = csWorkedLedger([csCorrection(5, 2, 'amend', 20), csCorrection(6, 2, 'amend', 30), csCorrection(7, 3, 'void', null)]);

    $effective = (new ApplyCorrections)->apply($ledger);

    expect(csUnits($effective))->toBe([2 => 30, 3 => 0, 4 => 10])
        ->and($effective->allocations[0]->correctedFrom)->toBe(40);
});

it('gives the same result whatever order the corrections arrive in', function () {
    $corrections = [csCorrection(5, 2, 'amend', 20), csCorrection(6, 2, 'amend', 30), csCorrection(7, 3, 'void', null)];
    $expected = (new ApplyCorrections)->apply(csWorkedLedger($corrections));

    foreach ([[2, 0, 1], [1, 2, 0], [2, 1, 0]] as $order) {
        $shuffled = array_map(fn (int $i) => $corrections[$i], $order);

        expect((new ApplyCorrections)->apply(csWorkedLedger($shuffled))->allocations)->toEqual($expected->allocations);
    }
});

it('ignores a correction that names an unknown allocation', function () {
    $ledger = csWorkedLedger([csCorrection(5, 999, 'amend', 7)]);

    expect(csUnits((new ApplyCorrections)->apply($ledger)))->toBe([2 => 40, 3 => 50, 4 => 10]);
});

it('returns entries without corrections as they are', function () {
    $ledger = csWorkedLedger([csCorrection(5, 2, 'amend', 20)]);
    $effective = (new ApplyCorrections)->apply($ledger);

    expect($effective->allocations[1])->toBe($ledger->allocations[1])
        ->and($effective->allocations[2]->correctedFrom)->toBeNull()
        ->and($effective->allocations[2]->voided)->toBeFalse();
});

it('keeps the expiry of an active Temporary entry when its units change', function () {
    $ledger = fn (array $corrections) => new Ledger([new RestockEntry(1, 1, 100)], [csEntry(2, 4, 1, 'temporary')], $corrections);
    $expiry = CarbonImmutable::parse(CS_T)->addHours(24);

    $lowered = csCalculator()->calculateAt($ledger([csCorrection(3, 2, 'amend', 2)]), $expiry->subMillisecond());
    $atExpiry = csCalculator()->calculateAt($ledger([csCorrection(3, 2, 'amend', 2)]), $expiry);

    expect($lowered->remaining)->toBe(98)
        ->and($lowered->effective->allocations[0]->expiresAt)->toEqual($expiry)
        ->and($atExpiry->remaining)->toBe(100);
});
