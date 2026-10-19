<?php

use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\RestockEntry;
use Carbon\CarbonImmutable;

function at(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-10-20 {$time} UTC");
}

function allocationAt(int $seq, string $time): AllocationEntry
{
    return new AllocationEntry($seq, $seq, 1, 'consumed', 5, 'bronze', at($time));
}

function mixedLedger(): Ledger
{
    return new Ledger(
        [new RestockEntry(1, 1, 100, at('08:00:00')), new RestockEntry(2, 5, 50, at('13:00:00'))],
        [allocationAt(2, '09:00:00'), allocationAt(3, '10:00:00')],
        [new CorrectionEntry(1, 4, 2, 'amend', 3, at('11:00:00')), new CorrectionEntry(2, 6, 3, 'void', null, at('14:00:00'))],
    );
}

it('keeps only the entries numbered at or below the sequence, in all three lists', function () {
    $asOf = mixedLedger()->asOf(4);

    expect(array_map(fn ($e) => $e->seq, $asOf->restocks))->toBe([1])
        ->and(array_map(fn ($e) => $e->seq, $asOf->allocations))->toBe([2, 3])
        ->and(array_map(fn ($e) => $e->seq, $asOf->corrections))->toBe([4]);
});

it('reports the newest entry time, counting restocks and corrections as well as allocations', function () {
    $ledger = mixedLedger();

    expect($ledger->instantAt(3))->toEqual(at('10:00:00'))
        // A correction newer than every allocation moves the instant.
        ->and($ledger->instantAt(4))->toEqual(at('11:00:00'))
        // So does a restock.
        ->and($ledger->instantAt(5))->toEqual(at('13:00:00'))
        ->and($ledger->instantAt(6))->toEqual(at('14:00:00'))
        ->and($ledger->instantAt(PHP_INT_MAX))->toEqual(at('14:00:00'));
});

it('skips entries without a time and gives null when none has one', function () {
    $ledger = new Ledger([new RestockEntry(1, 1, 100)], [], [new CorrectionEntry(1, 2, 9, 'void', null)]);

    expect($ledger->instantAt(2))->toBeNull();

    $withAllocation = new Ledger($ledger->restocks, [allocationAt(3, '09:00:00')], $ledger->corrections);
    expect($withAllocation->instantAt(3))->toEqual(at('09:00:00'));
});

it('evaluates at the receipt when it is the newest time, and at the newest entry time otherwise', function () {
    $ledger = mixedLedger();

    expect($ledger->evaluationInstant(at('15:00:00')))->toEqual(at('15:00:00'))
        // Received at 12:00 but committed after the 14:00 correction: it is evaluated at 14:00.
        ->and($ledger->evaluationInstant(at('12:00:00')))->toEqual(at('14:00:00'))
        ->and((new Ledger([], []))->evaluationInstant(at('12:00:00')))->toEqual(at('12:00:00'));
});

it('never decreases as the sequence grows', function () {
    $ledger = mixedLedger();
    $previous = null;

    foreach (range(1, 6) as $seq) {
        $instant = $ledger->instantAt($seq);
        if ($previous !== null) {
            expect($instant >= $previous)->toBeTrue();
        }
        $previous = $instant;
    }
});
