<?php

use App\Models\Allocation;
use App\Models\Pool;
use App\Models\User;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\LedgerRepository;
use Database\Seeders\DatabaseSeeder;

function getAs(?User $user, string $uri)
{
    return test()->getJson($uri, $user === null ? [] : ['X-Demo-User' => (string) $user->id]);
}

/**
 * Add $count allocations of 1 unit each to the pool, at seq 2.., and bring the pool version up to date.
 */
function addAllocations(Pool $pool, User $operator, int $count, int $granted = 1, int $requested = 1): void
{
    Allocation::factory()->count($count)
        ->sequence(fn ($sequence) => ['seq' => $sequence->index + 2])
        ->create(['pool_id' => $pool->id, 'operator_id' => $operator->id, 'granted' => $granted, 'requested' => $requested]);

    $pool->update(['version' => $count + 1]);
}

it('shows the whole pool log newest first', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 3)->assertCreated();
    submitAllocation($pool, $ben, 2)->assertCreated();

    $log = getAs($ana, "/api/pools/{$pool->id}")->assertOk()->json('log');

    expect(array_column($log, 'seq'))->toBe([3, 2, 1])
        ->and(array_column($log, 'kind'))->toBe(['allocation', 'allocation', 'restock'])
        // The whole pool's entries are there, not only the viewer's own.
        ->and($log[0]['operator']['name'])->toBe('Ben')
        ->and($log[1]['operator']['name'])->toBe('Ana');
});

it('shows the seeded restock as seq 1, and as the only entry of a fresh pool', function () {
    $this->seed(DatabaseSeeder::class);

    $snapshot = getAs(User::find(1), '/api/pools/1')->assertOk();

    expect($snapshot->json('log'))->toHaveCount(1)
        ->and($snapshot->json('log.0'))->toMatchArray(['seq' => 1, 'kind' => 'restock', 'actor' => null, 'amount' => 100, 'note' => 'Initial stock'])
        ->and($snapshot->json('remaining'))->toBe(100)
        ->and($snapshot->json('version'))->toBe(1)
        ->and($snapshot->json('log_has_more'))->toBeFalse()
        ->and($snapshot->json('pool'))->toBe(['id' => 1, 'name' => 'Main warehouse']);
});

it('has a version equal to the highest log seq and a remaining that matches the calculator', function () {
    $pool = poolWithRemaining(20);
    $ana = operatorNamed('Ana');
    foreach ([3, 4, 5] as $units) {
        submitAllocation($pool, $ana, $units)->assertCreated();
    }

    $snapshot = getAs($ana, "/api/pools/{$pool->id}")->assertOk();
    $calculated = app(PoolCalculator::class)->calculate(app(LedgerRepository::class)->load($pool));

    expect($snapshot->json('version'))->toBe(max(array_column($snapshot->json('log'), 'seq')))
        ->and($snapshot->json('version'))->toBe($calculated->version)
        ->and($snapshot->json('remaining'))->toBe($calculated->remaining)
        ->and($snapshot->json('remaining'))->toBe(8);
});

it('answers a viewer who has no allocations without an error, and no operator id for a supervisor', function () {
    $pool = poolWithRemaining(10);
    $quiet = operatorNamed('Quiet');
    $dana = User::factory()->supervisor()->create(['name' => 'Dana']);
    submitAllocation($pool, operatorNamed('Ana'), 2)->assertCreated();

    getAs($quiet, "/api/pools/{$pool->id}")->assertOk()
        ->assertJsonPath('viewer', ['operator_id' => $quiet->id, 'status' => 'standard', 'tier' => 'bronze', 'window_seconds' => 86400, 'holdings' => []])
        ->assertJsonCount(2, 'log');

    getAs($dana, "/api/pools/{$pool->id}")->assertOk()
        ->assertJsonPath('viewer.operator_id', null);
});

it('shows a zero grant in the log as requested N, granted 0', function () {
    $pool = poolWithRemaining(2);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 2)->assertCreated();
    submitAllocation($pool, $ben, 3)->assertCreated();

    $entry = getAs($ben, "/api/pools/{$pool->id}")->assertOk()->json('log.0');

    expect($entry)->toMatchArray(['kind' => 'allocation', 'requested' => 3, 'granted' => 0, 'outcome' => 'none'])
        ->and($entry['operator']['name'])->toBe('Ben');
});

it('pages through 120 allocations with no overlap and no gap', function () {
    $pool = poolWithRemaining(500);
    $ana = operatorNamed('Ana');
    addAllocations($pool, $ana, 120);

    $snapshot = getAs($ana, "/api/pools/{$pool->id}")->assertOk();
    $first = $snapshot->json('log');

    expect($first)->toHaveCount(100)
        ->and($snapshot->json('log_has_more'))->toBeTrue()
        ->and($snapshot->json('version'))->toBe(121)
        ->and($first[0]['seq'])->toBe(121)
        ->and(end($first)['seq'])->toBe(22)
        ->and($snapshot->json('remaining'))->toBe(380);

    $page = getAs($ana, "/api/pools/{$pool->id}/log?before=".end($first)['seq'])->assertOk();

    // 21 entries remain: seq 21 down to 1 (20 allocations and the restock).
    expect($page->json('entries'))->toHaveCount(21)
        ->and($page->json('has_more'))->toBeFalse()
        ->and(array_column($page->json('entries'), 'seq'))->toBe(range(21, 1));

    $all = array_merge(array_column($first, 'seq'), array_column($page->json('entries'), 'seq'));
    expect($all)->toBe(range(121, 1));
});

it('pages the log with a limit and says whether more remain', function () {
    $pool = poolWithRemaining(500);
    $ana = operatorNamed('Ana');
    addAllocations($pool, $ana, 12);

    $one = getAs($ana, "/api/pools/{$pool->id}/log?limit=5")->assertOk();
    $two = getAs($ana, "/api/pools/{$pool->id}/log?limit=5&before=".$one->json('entries.4.seq'))->assertOk();
    $three = getAs($ana, "/api/pools/{$pool->id}/log?limit=5&before=".$two->json('entries.4.seq'))->assertOk();

    expect(array_column($one->json('entries'), 'seq'))->toBe([13, 12, 11, 10, 9])
        ->and($one->json('has_more'))->toBeTrue()
        ->and(array_column($two->json('entries'), 'seq'))->toBe([8, 7, 6, 5, 4])
        ->and($two->json('has_more'))->toBeTrue()
        ->and(array_column($three->json('entries'), 'seq'))->toBe([3, 2, 1])
        ->and($three->json('has_more'))->toBeFalse();
});

it('is exact at the page boundary: a page that ends on the oldest entry has no more', function () {
    $pool = poolWithRemaining(500);
    addAllocations($pool, operatorNamed('Ana'), 4);

    $page = getAs(operatorNamed('Ben'), "/api/pools/{$pool->id}/log?limit=5")->assertOk();

    expect($page->json('entries'))->toHaveCount(5)
        ->and($page->json('has_more'))->toBeFalse();
});

it('defaults the log page to 50 entries and caps the limit at 100', function () {
    $pool = poolWithRemaining(500);
    $ana = operatorNamed('Ana');
    addAllocations($pool, $ana, 130);

    expect(getAs($ana, "/api/pools/{$pool->id}/log")->json('entries'))->toHaveCount(50)
        ->and(getAs($ana, "/api/pools/{$pool->id}/log?limit=100")->json('entries'))->toHaveCount(100);
});

it('rejects a bad before or limit with 422', function (string $query) {
    $pool = poolWithRemaining(10);

    getAs(operatorNamed('Ana'), "/api/pools/{$pool->id}/log?{$query}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
})->with([
    'limit zero' => 'limit=0',
    'limit above the cap' => 'limit=101',
    'limit not a number' => 'limit=abc',
    'limit fractional' => 'limit=1.5',
    'before zero' => 'before=0',
    'before negative' => 'before=-3',
    'before not a number' => 'before=abc',
]);

it('lets any role read the snapshot and the log, and needs a known user and pool', function () {
    $pool = poolWithRemaining(10);
    $dana = User::factory()->supervisor()->create(['name' => 'Dana']);

    getAs($dana, "/api/pools/{$pool->id}")->assertOk();
    getAs($dana, "/api/pools/{$pool->id}/log")->assertOk();
    getAs(null, "/api/pools/{$pool->id}")->assertStatus(401)->assertJsonPath('error.code', 'unknown_user');
    getAs(null, "/api/pools/{$pool->id}/log")->assertStatus(401);
    getAs($dana, '/api/pools/999999')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    getAs($dana, '/api/pools/999999/log')->assertStatus(404);
});

it('asks who you are before it says whether a pool exists', function () {
    getAs(null, '/api/pools/999999')->assertStatus(401)->assertJsonPath('error.code', 'unknown_user');
    getAs(null, '/api/pools/999999/log')->assertStatus(401);
});
