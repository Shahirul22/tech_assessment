<?php

use App\Models\Allocation;
use App\Models\Pool;
use App\Models\Restock;
use App\Models\User;
use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\RestockEntry;
use App\Pool\LedgerRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('loads a pool\'s ledger as the calculator\'s input', function () {
    $pool = Pool::factory()->create(['version' => 4]);
    $ana = User::factory()->operator('bronze')->create(['name' => 'Ana']);
    $ben = User::factory()->operator('silver')->create(['name' => 'Ben']);
    $dana = User::factory()->supervisor()->create(['name' => 'Dana']);
    $at = CarbonImmutable::parse('2026-10-17 10:00:00.123456 UTC');

    Restock::factory()->create(['pool_id' => $pool->id, 'seq' => 1, 'amount' => 100]);
    // Inserted out of order on purpose: the ledger is ordered by seq, not by insert order.
    $temporary = Allocation::factory()->create([
        'pool_id' => $pool->id, 'seq' => 3, 'operator_id' => $ben->id, 'type' => 'temporary',
        'requested' => 4, 'granted' => 4, 'tier_at_log' => 'silver', 'effective_at' => $at->addSecond(), 'idempotency_key' => 'k3',
    ]);
    $consumed = Allocation::factory()->create([
        'pool_id' => $pool->id, 'seq' => 2, 'operator_id' => $ana->id, 'type' => 'consumed',
        'requested' => 5, 'granted' => 3, 'tier_at_log' => 'bronze', 'effective_at' => $at, 'idempotency_key' => 'k2',
    ]);
    DB::table('corrections')->insert([
        'pool_id' => $pool->id, 'seq' => 4, 'allocation_id' => $consumed->id, 'supervisor_id' => $dana->id,
        'kind' => 'amend', 'from_amount' => 3, 'new_amount' => 2, 'reason' => 'typo', 'effective_at' => $at, 'recorded_at' => $at,
        'idempotency_key' => 'c4',
    ]);

    $ledger = app(LedgerRepository::class)->load($pool);

    expect($ledger)->toBeInstanceOf(Ledger::class)
        ->and($ledger->restocks)->toHaveCount(1)
        ->and($ledger->restocks[0])->toBeInstanceOf(RestockEntry::class)
        ->and($ledger->restocks[0]->seq)->toBe(1)
        ->and($ledger->restocks[0]->amount)->toBe(100)
        ->and(array_map(fn (AllocationEntry $a) => $a->seq, $ledger->allocations))->toBe([2, 3]);

    [$first, $second] = $ledger->allocations;

    // units is what was granted (3 of the 5 requested), and a loaded entry is active.
    expect($first)->toBeInstanceOf(AllocationEntry::class)
        ->and($first->id)->toBe($consumed->id)
        ->and($first->operatorId)->toBe($ana->id)
        ->and($first->type)->toBe('consumed')
        ->and($first->units)->toBe(3)
        ->and($first->tierAtLog)->toBe('bronze')
        ->and($first->active)->toBeTrue()
        // The instant keeps its microseconds through Postgres.
        ->and($first->effectiveAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($first->effectiveAt->format('Y-m-d\TH:i:s.u'))->toBe('2026-10-17T10:00:00.123456')
        ->and($second->type)->toBe('temporary')
        ->and($second->units)->toBe(4)
        ->and($second->tierAtLog)->toBe('silver')
        ->and($second->operatorId)->toBe($ben->id);

    expect($ledger->corrections)->toHaveCount(1)
        ->and($ledger->corrections[0])->toBeInstanceOf(CorrectionEntry::class)
        ->and($ledger->corrections[0]->seq)->toBe(4)
        ->and($ledger->corrections[0]->allocationId)->toBe($consumed->id)
        ->and($ledger->corrections[0]->kind)->toBe('amend')
        ->and($ledger->corrections[0]->newAmount)->toBe(2);

    expect($temporary->id)->toBe($second->id);
});

it('keeps a zero grant in the ledger as zero units', function () {
    $pool = Pool::factory()->create();
    Allocation::factory()->create(['pool_id' => $pool->id, 'seq' => 2, 'requested' => 3, 'granted' => 0]);

    $ledger = app(LedgerRepository::class)->load($pool);

    expect($ledger->allocations)->toHaveCount(1)
        ->and($ledger->allocations[0]->units)->toBe(0);
});

it('excludes another pool\'s rows', function () {
    $pool = Pool::factory()->create();
    $other = Pool::factory()->create();
    Restock::factory()->create(['pool_id' => $pool->id, 'seq' => 1, 'amount' => 100]);
    Allocation::factory()->create(['pool_id' => $pool->id, 'seq' => 2]);
    Restock::factory()->create(['pool_id' => $other->id, 'seq' => 1, 'amount' => 999]);
    Allocation::factory()->count(2)->sequence(['seq' => 2], ['seq' => 3])->create(['pool_id' => $other->id]);

    $ledger = app(LedgerRepository::class)->load($pool);

    expect($ledger->restocks)->toHaveCount(1)
        ->and($ledger->restocks[0]->amount)->toBe(100)
        ->and($ledger->allocations)->toHaveCount(1);
});

it('returns an empty ledger for a pool with no history', function () {
    $ledger = app(LedgerRepository::class)->load(Pool::factory()->create());

    expect($ledger->restocks)->toBe([])
        ->and($ledger->allocations)->toBe([])
        ->and($ledger->corrections)->toBe([]);
});

it('reads the ledger with exactly three queries', function () {
    $pool = Pool::factory()->create();
    Restock::factory()->create(['pool_id' => $pool->id, 'seq' => 1]);
    Allocation::factory()->count(3)->sequence(['seq' => 2], ['seq' => 3], ['seq' => 4])->create(['pool_id' => $pool->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(LedgerRepository::class)->load($pool);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(3);
});

it('loads the time of every restock and correction with its microseconds', function () {
    $pool = Pool::factory()->create();
    $dana = User::factory()->supervisor()->create();
    $allocation = Allocation::factory()->create(['pool_id' => $pool->id, 'seq' => 2, 'requested' => 3, 'granted' => 3]);
    $restockedAt = '2026-10-17 09:00:00.654321+00';
    $correctedAt = '2026-10-17 12:30:15.000789+00';

    DB::table('restocks')->insert(['pool_id' => $pool->id, 'seq' => 1, 'amount' => 100, 'recorded_at' => $restockedAt]);
    DB::table('corrections')->insert([
        'pool_id' => $pool->id, 'seq' => 3, 'allocation_id' => $allocation->id, 'supervisor_id' => $dana->id,
        'kind' => 'void', 'from_amount' => 3, 'new_amount' => null, 'reason' => 'dup',
        'effective_at' => $correctedAt, 'recorded_at' => $correctedAt, 'idempotency_key' => 'c3',
    ]);

    $ledger = app(LedgerRepository::class)->load($pool);

    expect($ledger->restocks[0]->at->format('Y-m-d\TH:i:s.u'))->toBe('2026-10-17T09:00:00.654321')
        ->and($ledger->corrections[0]->at->format('Y-m-d\TH:i:s.u'))->toBe('2026-10-17T12:30:15.000789');
});
