<?php

use App\Pool\Audit\HistoryReplay;
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

function hrAt(string $time, int $day = 17): CarbonImmutable
{
    return CarbonImmutable::parse("2026-10-{$day} {$time} UTC");
}

function hrReplay(): HistoryReplay
{
    $rules = app(RulesConfig::class);

    return new HistoryReplay(
        new PoolCalculator(new FrozenClock(hrAt('15:00')), $rules, new ApplyCorrections, new ApplyExpiry, new ComputeStatus),
        $rules,
    );
}

function hrGrant(int $seq, int $units, int $operatorId, string $time, string $type = 'consumed', int $day = 17): AllocationEntry
{
    return new AllocationEntry($seq, $seq, $operatorId, $type, $units, 'bronze', hrAt($time, $day));
}

/** The PRD example: capacity 100, Ana 40, Ben 50, Cara 10, restock +50 at 13:00, then a correction at 14:00. */
function hrWorkedLedger(array $corrections): Ledger
{
    return new Ledger(
        [new RestockEntry(1, 1, 100, hrAt('08:00')), new RestockEntry(5, 5, 50, hrAt('13:00'))],
        [hrGrant(2, 40, 1, '09:00'), hrGrant(3, 50, 2, '10:00'), hrGrant(4, 10, 3, '11:00')],
        $corrections,
    );
}

it('replays the corrected history and flags the stretch where the pool was below zero', function () {
    $ledger = hrWorkedLedger([new CorrectionEntry(6, 6, 2, 'amend', 60, hrAt('14:00'))]);

    $points = hrReplay()->points($ledger, hrAt('15:00'));

    expect(array_map(fn ($p) => [$p->seq, $p->kind, $p->remaining], $points))->toBe([
        [1, 'restock', 100], [2, 'allocation', 40], [3, 'allocation', -10], [4, 'allocation', -20], [5, 'restock', 30], [6, 'correction', 30],
    ]);

    $windows = hrReplay()->overdrawn($points, $ledger);

    // Ben's entry (10:00) already takes it to -10; the -20 stretch, 11:00 to 13:00, lies inside this window.
    expect($windows)->toHaveCount(1)
        ->and($windows[0]->from->seq)->toBe(3)
        ->and($windows[0]->to->seq)->toBe(5)
        ->and($windows[0]->lowest)->toBe(-20)
        ->and($windows[0]->correctionSeqs)->toBe([6])
        ->and($windows[0]->from->at)->toEqual(hrAt('10:00'))
        ->and($windows[0]->to->at)->toEqual(hrAt('13:00'));
});

it('finds no window when there is no correction', function () {
    $ledger = hrWorkedLedger([]);

    expect(hrReplay()->overdrawn(hrReplay()->points($ledger, hrAt('15:00')), $ledger))->toBe([]);
});

it('finds no window when a correction only lowers', function () {
    $ledger = hrWorkedLedger([new CorrectionEntry(6, 6, 2, 'amend', 20, hrAt('14:00'))]);

    $points = hrReplay()->points($ledger, hrAt('15:00'));

    expect(hrReplay()->overdrawn($points, $ledger))->toBe([])
        ->and(min(array_map(fn ($p) => $p->remaining, $points)))->toBeGreaterThanOrEqual(0);
});

it('lets an expiry end a window', function () {
    // Capacity 10: Ana holds a Temporary 4 (expires at 09:00 next day), Ben consumes 6. The expired entry is raised to 9
    // the next day. History is -5 after Ben's entry until Ana's units return.
    $ledger = new Ledger(
        [new RestockEntry(1, 1, 10, hrAt('08:00'))],
        [hrGrant(2, 4, 1, '09:00', 'temporary'), hrGrant(3, 6, 2, '10:00')],
        [new CorrectionEntry(4, 4, 2, 'amend', 9, hrAt('10:00', 18))],
    );

    $points = hrReplay()->points($ledger, hrAt('12:00', 18));
    $windows = hrReplay()->overdrawn($points, $ledger);

    expect(array_map(fn ($p) => [$p->kind, $p->remaining], $points))->toBe([
        ['restock', 10], ['allocation', 1], ['allocation', -5], ['expiry', 4], ['correction', 4],
    ])
        ->and($windows)->toHaveCount(1)
        ->and($windows[0]->to->kind)->toBe('expiry')
        ->and($windows[0]->to->at)->toEqual(hrAt('09:00', 18))
        ->and($windows[0]->lowest)->toBe(-5)
        ->and($windows[0]->correctionSeqs)->toBe([4]);
});

it('keeps a window open when the pool never recovers', function () {
    $ledger = new Ledger(
        [new RestockEntry(1, 1, 10, hrAt('08:00'))],
        [hrGrant(2, 4, 1, '09:00'), hrGrant(3, 6, 2, '10:00')],
        [new CorrectionEntry(4, 4, 2, 'amend', 9, hrAt('11:00'))],
    );

    $windows = hrReplay()->overdrawn(hrReplay()->points($ledger, hrAt('12:00')), $ledger);

    expect($windows)->toHaveCount(1)
        ->and($windows[0]->to)->toBeNull()
        ->and($windows[0]->lowest)->toBe(-5);
});

it('does not count a lowering or an earlier raise as the raise behind a window', function () {
    $ledger = hrWorkedLedger([
        new CorrectionEntry(6, 6, 3, 'amend', 30, hrAt('14:00')),
        new CorrectionEntry(7, 7, 3, 'amend', 20, hrAt('14:30')),
    ]);

    $windows = hrReplay()->overdrawn(hrReplay()->points($ledger, hrAt('15:00')), $ledger);

    // Ben 50 -> 30 lowers, then 30 -> 20 lowers again: neither raises anything.
    expect($windows)->toBe([]);
});
