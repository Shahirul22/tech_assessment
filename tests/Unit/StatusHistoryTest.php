<?php

use App\Pool\Audit\StatusHistory;
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

function shAt(string $time, int $day = 17): CarbonImmutable
{
    return CarbonImmutable::parse("2026-10-{$day} {$time} UTC");
}

function shHistory(): StatusHistory
{
    $rules = app(RulesConfig::class);

    return new StatusHistory(
        new PoolCalculator(new FrozenClock(shAt('15:00')), $rules, new ApplyCorrections, new ApplyExpiry, new ComputeStatus),
        $rules,
    );
}

function shGrant(int $seq, int $units, int $operatorId, string $time, string $type = 'consumed'): AllocationEntry
{
    return new AllocationEntry($seq, $seq, $operatorId, $type, $units, 'bronze', shAt($time));
}

function shLedger(array $allocations, array $corrections): Ledger
{
    return new Ledger([new RestockEntry(1, 1, 1000, shAt('08:00'))], $allocations, $corrections);
}

/** Ana holds 28 at 09:00 and 5 at 10:30. Lowering the first to $to at 14:00 is correction #4. */
function shAna(int $to): Ledger
{
    return shLedger(
        [shGrant(2, 28, 1, '09:00'), shGrant(3, 5, 1, '10:30')],
        [new CorrectionEntry(4, 4, 2, 'amend', $to, shAt('14:00'))],
    );
}

it('shows the status before and after at the correction moment and at the later points where it differs', function () {
    $change = shHistory()->forCorrection(shAna(20), 4, shAt('15:00'), 'Ana');

    // 28 + 5 = 33 (high) becomes 20 + 5 = 25 (elevated); after the first request alone 28 and 20 are both elevated.
    expect($change->before)->toBe('high')
        ->and($change->after)->toBe('elevated')
        ->and(array_map(fn ($p) => [$p['label'], $p['seq'], $p['before'], $p['after']], $change->points))->toBe([
            ['when corrected', 4, 'high', 'elevated'],
            ["after Ana's request #3", 3, 'high', 'elevated'],
            ['now', null, 'high', 'elevated'],
        ])
        ->and($change->points[1]['at'])->toEqual(shAt('10:30'));
});

it('lists a point only when before and after differ', function () {
    // Lowering 28 to 25 leaves 33 -> 30: high both ways at every point.
    $change = shHistory()->forCorrection(shAna(25), 4, shAt('15:00'), 'Ana');

    expect($change->before)->toBe('high')
        ->and($change->after)->toBe('high')
        ->and($change->points)->toHaveCount(1);
});

it('moves the status exactly at the thresholds', function (int $first, int $second, int $to, string $before, string $after) {
    $ledger = shLedger([shGrant(2, $first, 1, '09:00'), shGrant(3, $second, 1, '10:30')], [new CorrectionEntry(4, 4, 2, 'amend', $to, shAt('14:00'))]);

    $change = shHistory()->forCorrection($ledger, 4, shAt('15:00'), 'Ana');

    expect([$change->before, $change->after])->toBe([$before, $after]);
})->with([
    'holding 30 drops to 29: high to elevated' => [25, 5, 24, 'high', 'elevated'],
    'holding 10 drops to 9: elevated to standard' => [8, 2, 7, 'elevated', 'standard'],
    'holding 30 stays 30' => [20, 10, 25, 'high', 'high'],
]);

it('gives standard after a void to nothing held', function () {
    $ledger = shLedger([shGrant(2, 28, 1, '09:00')], [new CorrectionEntry(4, 4, 2, 'void', null, shAt('14:00'))]);

    $change = shHistory()->forCorrection($ledger, 4, shAt('15:00'), 'Ana');

    expect($change->before)->toBe('elevated')
        ->and($change->after)->toBe('standard');
});

it('gives only the correction moment and now for an operator with no other entries', function () {
    $ledger = shLedger([shGrant(2, 12, 1, '09:00')], [new CorrectionEntry(3, 3, 2, 'amend', 5, shAt('14:00'))]);

    $change = shHistory()->forCorrection($ledger, 3, shAt('15:00'), 'Ana');

    // The first request is the corrected one itself, so it is a point too: 12 elevated before, 5 standard after.
    expect(array_map(fn ($p) => $p['label'], $change->points))->toBe(['when corrected', "after Ana's request #2", 'now']);
});

it('never evaluates or lists another operator', function () {
    $ledger = shLedger(
        [shGrant(2, 28, 1, '09:00'), shGrant(3, 50, 2, '10:00'), shGrant(4, 10, 3, '11:00')],
        [new CorrectionEntry(5, 5, 2, 'amend', 20, shAt('14:00'))],
    );

    $change = shHistory()->forCorrection($ledger, 5, shAt('15:00'), 'Ana');

    expect(array_map(fn ($p) => $p['seq'], $change->points))->not->toContain(3, 4);
});

it('judges each correction by the corrections before it', function () {
    $ledger = shLedger(
        [shGrant(2, 28, 1, '09:00'), shGrant(3, 5, 1, '10:30')],
        [new CorrectionEntry(4, 4, 2, 'amend', 20, shAt('14:00')), new CorrectionEntry(5, 5, 2, 'amend', 5, shAt('14:30'))],
    );

    $first = shHistory()->forCorrection($ledger, 4, shAt('15:00'), 'Ana');
    $second = shHistory()->forCorrection($ledger, 5, shAt('15:00'), 'Ana');

    // #4 took 33 (high) to 25 (elevated); #5 took 25 (elevated) to 10 (elevated).
    expect([$first->before, $first->after])->toBe(['high', 'elevated'])
        ->and([$second->before, $second->after])->toBe(['elevated', 'elevated']);
});

it('lists the point where a Temporary entry expired when the status differs there', function () {
    // Ana: Temporary 30 at 09:00 (bronze 24 h, expires 09:00 next day) and Consumed 12 at 09:30; the 30 is lowered to 8.
    $ledger = shLedger(
        [shGrant(2, 30, 1, '09:00', 'temporary'), shGrant(3, 12, 1, '09:30')],
        [new CorrectionEntry(4, 4, 2, 'amend', 8, shAt('14:00'))],
    );

    $change = shHistory()->forCorrection($ledger, 4, shAt('15:00'), 'Ana');

    // 42 (high) vs 20 (elevated) while the entry is active at the correction moment.
    expect([$change->before, $change->after])->toBe(['high', 'elevated']);

    $afterExpiry = shHistory()->forCorrection($ledger, 4, shAt('15:00', 19), 'Ana');
    $labels = array_map(fn ($p) => $p['label'], $afterExpiry->points);

    // Once the 30 expired, the correction no longer matters: 12 either way, so "now" is unlisted.
    expect($labels)->not->toContain('now');
});
