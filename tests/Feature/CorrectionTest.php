<?php

use App\Models\Allocation;
use App\Models\Correction;
use App\Models\Pool;
use App\Pool\Exceptions\AllocationVoided;
use App\Pool\Exceptions\CorrectionRefused;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    freezeClock('2026-10-17 12:00:00 UTC');
});

it('frees units when a lowering is made and leaves the other operators alone', function () {
    $world = workedPool();

    $result = correct($world, 2, 'amend', 20);

    expect($result->pool->remaining)->toBe(20)
        ->and($result->replayed)->toBeFalse()
        ->and($result->correction->seq)->toBe(5)
        ->and($result->pool->version)->toBe(5)
        ->and($result->pool->statuses[$world['ana']->id])->toBe('elevated')
        ->and($result->pool->statuses[$world['ben']->id])->toBe('high')
        ->and($result->pool->statuses[$world['cara']->id])->toBe('elevated')
        // Ben's and Cara's grants are untouched.
        ->and(collect($result->pool->effective->allocations)->mapWithKeys(fn ($a) => [$a->seq => $a->units])->all())->toBe([2 => 20, 3 => 50, 4 => 10]);

    // The freed units are available at once, and the original row is never changed.
    $cara = requestUnits($world, $world['cara'], 20);

    expect($cara->allocation->granted)->toBe(20)
        ->and($cara->pool->remaining)->toBe(0)
        ->and($world['allocations']['ana']->fresh()->granted)->toBe(40);
});

it('refuses a correction that raises an allocation beyond the free units', function () {
    $world = workedPool();

    try {
        correct($world, 2, 'amend', 60);
        $this->fail('Expected the raise to be refused.');
    } catch (CorrectionRefused $e) {
        expect([$e->needed, $e->free, $e->allocationSeq])->toBe([20, 0, 2])
            ->and($e->getMessage())->toBe('Needs 20 more units, 0 are free. Restock first.');
    }

    expect(correctionCount($world))->toBe(0)
        ->and($world['pool']->fresh()->version)->toBe(4);
});

it('accepts the same raise once a restock has made room', function () {
    $world = workedPool();
    restockUnits($world, 50);

    $result = correct($world, 2, 'amend', 60);

    expect($result->pool->remaining)->toBe(30)
        ->and($result->pool->statuses[$world['ana']->id])->toBe('high')
        ->and($result->correction->from_amount)->toBe(40);
});

it('accepts a raise that needs exactly the free units and refuses one unit more', function () {
    $world = workedPool();
    correct($world, 2, 'amend', 20);

    expect(fn () => correct($world, 4, 'amend', 31))->toThrow(CorrectionRefused::class);

    $result = correct($world, 4, 'amend', 30);

    expect($result->pool->remaining)->toBe(0);
});

it('checks a raise against the units its own status change is not counted in', function () {
    // Cara 10 -> 30 moves her status from elevated to high; the check is about units only.
    $world = workedPool();
    correct($world, 2, 'amend', 20);

    $result = correct($world, 4, 'amend', 30);

    expect($result->pool->statuses[$world['cara']->id])->toBe('high');
});

it('frees every unit of a voided entry and drops the operator to standard', function () {
    $world = workedPool();

    $result = correct($world, 2, 'void', reason: 'Duplicate entry');

    expect($result->pool->remaining)->toBe(40)
        ->and($result->pool->statuses[$world['ana']->id])->toBe('standard')
        ->and($result->correction->kind)->toBe('void')
        ->and($result->correction->new_amount)->toBeNull()
        ->and($result->correction->from_amount)->toBe(40)
        ->and($result->pool->effective->allocations[0]->voided)->toBeTrue();
});

it('corrects the oldest entry in the pool', function () {
    $world = workedPool();

    $result = correct($world, 2, 'amend', 39);

    expect($result->pool->remaining)->toBe(1)
        ->and($result->correction->allocation_id)->toBe($world['allocations']['ana']->id);
});

it('refuses to correct a voided entry again', function () {
    $world = workedPool();
    correct($world, 2, 'void');

    expect(fn () => correct($world, 2, 'amend', 10))->toThrow(AllocationVoided::class)
        ->and(fn () => correct($world, 2, 'void'))->toThrow(AllocationVoided::class);

    expect(correctionCount($world))->toBe(1);
});

it('refuses a correction that changes nothing', function () {
    $world = workedPool();

    $refused = fn (callable $call) => expect($call)->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['amount' => ['no_change']]));

    $refused(fn () => correct($world, 2, 'amend', 40));

    // After a lowering, the entry's current units are what a correction is compared with.
    correct($world, 2, 'amend', 20);
    $refused(fn () => correct($world, 2, 'amend', 20));

    expect(correctionCount($world))->toBe(1);
});

it('refuses to void an entry that already holds nothing', function () {
    $world = workedPool();
    $zero = Allocation::factory()->create([
        'pool_id' => $world['pool']->id, 'seq' => 5, 'operator_id' => $world['ana']->id, 'requested' => 3, 'granted' => 0,
        'tier_at_log' => 'bronze', 'effective_at' => '2026-10-17 11:30:00+00', 'idempotency_key' => 'zero',
    ]);
    $world['pool']->update(['version' => 5]);

    expect(fn () => correct($world, $zero->seq, 'void'))->toThrow(ValidationException::class);
});

it('answers not found for an unknown entry or an entry of another pool', function () {
    $world = workedPool();
    $other = workedPool();
    // An entry that exists only in the other pool.
    Allocation::factory()->create(['pool_id' => $other['pool']->id, 'seq' => 5, 'operator_id' => $other['ana']->id, 'idempotency_key' => 'elsewhere']);

    expect(fn () => correct($world, 99, 'amend', 5))->toThrow(ModelNotFoundException::class)
        // Entry 1 is the seeded restock, not an allocation.
        ->and(fn () => correct($world, 1, 'amend', 5))->toThrow(ModelNotFoundException::class)
        ->and(fn () => correct($world, 5, 'amend', 5))->toThrow(ModelNotFoundException::class);

    expect(correctionCount($world))->toBe(0);
});

it('lets a zero-grant entry be raised to what was asked when the units are free (PQ1)', function () {
    $world = workedPool();
    $eve = operatorNamed('Eve');
    $refused = requestUnits($world, $eve, 3);
    expect($refused->allocation->granted)->toBe(0);

    restockUnits($world, 3);

    $result = correct($world, $refused->allocation->seq, 'amend', 3);

    expect($result->pool->remaining)->toBe(0)
        ->and($result->correction->from_amount)->toBe(0);
});

it('records the units before each correction, so a second correction starts from the first', function () {
    $world = workedPool();

    $first = correct($world, 2, 'amend', 20);
    $second = correct($world, 2, 'amend', 30);

    expect([$first->correction->from_amount, $first->correction->new_amount])->toBe([40, 20])
        ->and([$second->correction->from_amount, $second->correction->new_amount])->toBe([20, 30])
        ->and($second->pool->remaining)->toBe(10);
});

it('shares one gapless numbering with restocks and allocations', function () {
    $world = workedPool();

    $seqs = [
        restockUnits($world, 10)->restock->seq,
        correct($world, 2, 'amend', 30)->correction->seq,
        requestUnits($world, $world['ben'], 1)->allocation->seq,
        correct($world, 3, 'void')->correction->seq,
    ];

    expect($seqs)->toBe([5, 6, 7, 8])
        ->and($world['pool']->fresh()->version)->toBe(8);
});
