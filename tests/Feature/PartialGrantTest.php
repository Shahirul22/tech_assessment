<?php

use App\Models\Allocation;
use Illuminate\Support\Facades\DB;

function ledgerRowCounts(): array
{
    return collect(['pools', 'restocks', 'allocations', 'corrections'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
        ->all();
}

it('grants the first request of 6 in full and the second of 6 only the 4 left', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');

    submitAllocation($pool, $ana, 6)->assertCreated()
        ->assertJsonPath('allocation.outcome', 'full')
        ->assertJsonPath('allocation.granted', 6)
        ->assertJsonPath('pool.remaining', 4);

    submitAllocation($pool, $ben, 6)->assertCreated()
        ->assertJsonPath('allocation.outcome', 'partial')
        ->assertJsonPath('allocation.requested', 6)
        ->assertJsonPath('allocation.granted', 4)
        ->assertJsonPath('pool.remaining', 0);

    expect(Allocation::sum('granted'))->toBe(10);
});

it('records a stale request against an empty pool as granted 0 and leaves the pool alone', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 10)->assertCreated();

    $response = submitAllocation($pool, $ben, 3)
        ->assertCreated()
        ->assertJsonPath('allocation.outcome', 'none')
        ->assertJsonPath('allocation.requested', 3)
        ->assertJsonPath('allocation.granted', 0)
        ->assertJsonPath('pool.remaining', 0);

    $stored = Allocation::where('operator_id', $ben->id)->sole();

    // A stored row, with its own seq, so it can appear in the log as "requested 3, granted 0" (FR1.8).
    expect($stored->requested)->toBe(3)
        ->and($stored->granted)->toBe(0)
        ->and($stored->seq)->toBe($response->json('allocation.seq'))
        ->and($pool->fresh()->version)->toBe($stored->seq)
        ->and(Allocation::sum('granted'))->toBe(10);
});

it('grants a request equal to the remaining units in full', function () {
    $pool = poolWithRemaining(10);

    submitAllocation($pool, operatorNamed('Ana'), 10)->assertCreated()
        ->assertJsonPath('allocation.outcome', 'full')
        ->assertJsonPath('allocation.granted', 10)
        ->assertJsonPath('pool.remaining', 0);
});

it('serves a request for the last unit once, then records the next as nothing granted', function () {
    $pool = poolWithRemaining(1);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');

    submitAllocation($pool, $ana, 1)->assertCreated()->assertJsonPath('allocation.outcome', 'full');
    submitAllocation($pool, $ben, 1)->assertCreated()
        ->assertJsonPath('allocation.outcome', 'none')
        ->assertJsonPath('allocation.granted', 0)
        ->assertJsonPath('pool.remaining', 0);
});

it('grants 10 of 11 when an API client asks for more than remains', function () {
    $pool = poolWithRemaining(10);

    submitAllocation($pool, operatorNamed('Ana'), 11)->assertCreated()
        ->assertJsonPath('allocation.outcome', 'partial')
        ->assertJsonPath('allocation.requested', 11)
        ->assertJsonPath('allocation.granted', 10)
        ->assertJsonPath('pool.remaining', 0);
});

it('never takes the pool below zero, however many requests arrive', function () {
    $pool = poolWithRemaining(7);
    $ana = operatorNamed('Ana');

    foreach (range(1, 6) as $ignored) {
        submitAllocation($pool, $ana, 3)->assertCreated();
    }

    expect(Allocation::pluck('granted')->all())->toBe([3, 3, 1, 0, 0, 0])
        ->and(Allocation::sum('granted'))->toBe(7);
});

it('keeps no waitlist: only the submitted requests exist afterwards', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    $before = ledgerRowCounts();

    submitAllocation($pool, $ana, 6)->assertCreated();
    submitAllocation($pool, $ben, 6)->assertCreated();
    submitAllocation($pool, $ben, 6)->assertCreated();

    $after = ledgerRowCounts();

    // Three submits, three new allocation rows; nothing else was created for the unmet remainder.
    expect($after['allocations'] - $before['allocations'])->toBe(3)
        ->and($after['restocks'])->toBe($before['restocks'])
        ->and($after['corrections'])->toBe($before['corrections'])
        ->and($after['pools'])->toBe($before['pools']);

    // And Ben's later request is a new submit, served only from what is free then.
    expect(Allocation::where('operator_id', $ben->id)->orderBy('seq')->pluck('granted')->all())->toBe([4, 0]);
});
