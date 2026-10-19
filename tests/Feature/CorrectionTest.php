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

it('lowers an entry over HTTP and returns the correction and the pool', function () {
    $world = workedPool();

    $response = postCorrection($world, $world['dana'], 2, ['kind' => 'amend', 'amount' => 20, 'reason' => 'Counted wrong'])
        ->assertCreated()
        ->assertJsonPath('pool.remaining', 20)
        ->assertJsonPath('pool.version', 5)
        ->assertJsonPath('correction.seq', 5)
        ->assertJsonPath('correction.allocation_seq', 2)
        ->assertJsonPath('correction.operator', ['id' => $world['ana']->id, 'name' => 'Ana'])
        ->assertJsonPath('correction.kind', 'amend')
        ->assertJsonPath('correction.from', 40)
        ->assertJsonPath('correction.to', 20)
        ->assertJsonPath('correction.reason', 'Counted wrong')
        ->assertJsonPath('correction.supervisor', ['id' => $world['dana']->id, 'name' => 'Dana']);

    expect($response->json('correction.effective_at'))->toBe('2026-10-17T12:00:00.000000Z');
});

it('refuses a raise beyond the free units over HTTP, records nothing, and accepts it after a restock', function () {
    $world = workedPool();
    $body = ['kind' => 'amend', 'amount' => 60, 'reason' => 'Miscounted'];

    postCorrection($world, $world['dana'], 2, $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'insufficient_free_units')
        ->assertJsonPath('error.message', 'Needs 20 more units, 0 are free. Restock first.')
        ->assertJsonPath('error.details', ['needed' => 20, 'free' => 0, 'allocation_seq' => 2]);

    expect(correctionCount($world))->toBe(0)
        ->and($world['pool']->fresh()->version)->toBe(4);

    restockUnits($world, 50);

    postCorrection($world, $world['dana'], 2, $body)->assertCreated()->assertJsonPath('pool.remaining', 30);
});

it('voids an entry over HTTP', function () {
    $world = workedPool();

    postCorrection($world, $world['dana'], 2, ['kind' => 'void', 'reason' => 'Duplicate'])
        ->assertCreated()
        ->assertJsonPath('correction.kind', 'void')
        ->assertJsonPath('correction.from', 40)
        ->assertJsonPath('correction.to', 0)
        ->assertJsonPath('pool.remaining', 40);
});

it('rejects invalid corrections with 422 validation_failed and records nothing', function (array $body, string $field) {
    $world = workedPool();

    postCorrection($world, $world['dana'], 2, $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

    expect(correctionCount($world))->toBe(0);
})->with([
    'blank reason' => [['kind' => 'amend', 'amount' => 20, 'reason' => '   '], 'reason'],
    'missing reason' => [['kind' => 'amend', 'amount' => 20], 'reason'],
    'reason too long' => [['kind' => 'amend', 'amount' => 20, 'reason' => str_repeat('a', 501)], 'reason'],
    'amount 0' => [['kind' => 'amend', 'amount' => 0, 'reason' => 'x'], 'amount'],
    'negative amount' => [['kind' => 'amend', 'amount' => -3, 'reason' => 'x'], 'amount'],
    'fractional amount' => [['kind' => 'amend', 'amount' => 2.5, 'reason' => 'x'], 'amount'],
    'text amount' => [['kind' => 'amend', 'amount' => '20', 'reason' => 'x'], 'amount'],
    'missing amount' => [['kind' => 'amend', 'reason' => 'x'], 'amount'],
    'amount on a void' => [['kind' => 'void', 'amount' => 5, 'reason' => 'x'], 'amount'],
    'unknown kind' => [['kind' => 'delete', 'reason' => 'x'], 'kind'],
    'missing kind' => [['amount' => 5, 'reason' => 'x'], 'kind'],
    'same amount' => [['kind' => 'amend', 'amount' => 40, 'reason' => 'x'], 'amount'],
]);

it('reports an unchanged amount as no_change on the amount field', function () {
    $world = workedPool();

    postCorrection($world, $world['dana'], 2, ['kind' => 'amend', 'amount' => 40, 'reason' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.amount', ['no_change']);
});

it('answers 404 for an unknown entry, an entry of another pool or a malformed number', function () {
    $world = workedPool();
    $other = workedPool();
    Allocation::factory()->create(['pool_id' => $other['pool']->id, 'seq' => 5, 'operator_id' => $other['ana']->id, 'idempotency_key' => 'elsewhere']);
    $body = ['kind' => 'amend', 'amount' => 5, 'reason' => 'x'];

    postCorrection($world, $world['dana'], 99, $body)->assertNotFound();
    postCorrection($world, $world['dana'], 5, $body)->assertNotFound();
    postCorrection($world, $world['dana'], 1, $body)->assertNotFound();
    postCorrection($world, $world['dana'], 'abc', $body)->assertNotFound();
    postCorrection($world, $world['dana'], 0, $body)->assertNotFound();
    postCorrection($world, $world['dana'], 2, $body, pool: Pool::factory()->make(['id' => 999999]))->assertNotFound();

    expect(correctionCount($world))->toBe(0);
});

it('refuses a correction of a voided entry with 422 allocation_voided', function () {
    $world = workedPool();
    correct($world, 2, 'void');

    postCorrection($world, $world['dana'], 2, ['kind' => 'amend', 'amount' => 10, 'reason' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'allocation_voided');
});

it('refuses an operator and an unknown user, and requires an Idempotency-Key', function () {
    $world = workedPool();
    $body = ['kind' => 'amend', 'amount' => 20, 'reason' => 'x'];

    postCorrection($world, $world['ana'], 2, $body)->assertForbidden()->assertJsonPath('error.code', 'forbidden_role');
    postCorrection($world, null, 2, $body)->assertStatus(401);
    postCorrection($world, $world['dana'], 2, $body, withKey: false)->assertStatus(400)->assertJsonPath('error.code', 'missing_idempotency_key');

    expect(correctionCount($world))->toBe(0);
});

it('replays a correction over HTTP with 200 and the same body, and refuses another payload with 409', function () {
    $world = workedPool();
    $body = ['kind' => 'amend', 'amount' => 20, 'reason' => 'Counted wrong'];
    $first = postCorrection($world, $world['dana'], 2, $body, 'K')->assertCreated();

    $replay = postCorrection($world, $world['dana'], 2, $body, 'K')->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json())->toBe($first->json());

    postCorrection($world, $world['dana'], 2, ['amount' => 25] + $body, 'K')->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_conflict');

    expect(correctionCount($world))->toBe(1);
});
