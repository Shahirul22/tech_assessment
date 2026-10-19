<?php

use App\Models\Allocation;
use App\Models\Pool;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function logOf(?User $user, Pool $pool, string $query = ''): array
{
    return test()->getJson("/api/pools/{$pool->id}/log{$query}", $user === null ? [] : ['X-Demo-User' => (string) $user->id])
        ->assertOk()->json();
}

function snapshotLog(User $user, Pool $pool): array
{
    return test()->getJson("/api/pools/{$pool->id}", ['X-Demo-User' => (string) $user->id])->assertOk()->json('log');
}

function kinds(array $entries): array
{
    return array_map(fn (array $entry) => $entry['kind'].':'.($entry['seq'] ?? $entry['allocation_seq']), $entries);
}

/**
 * A pool of 100 whose restock is stamped before the test's start, so every expiry is anchored after it.
 */
function expiryLogPool(): Pool
{
    $pool = poolWithRemaining(100);
    DB::table('restocks')->where('pool_id', $pool->id)->update(['recorded_at' => '2026-10-19 09:00:00+00']);

    return $pool;
}

function insertAllocation(Pool $pool, User $operator, int $seq, string $effectiveAt, string $type = 'consumed'): void
{
    Allocation::factory()->create([
        'pool_id' => $pool->id, 'seq' => $seq, 'operator_id' => $operator->id, 'type' => $type,
        'requested' => 1, 'granted' => 1, 'effective_at' => CarbonImmutable::parse($effectiveAt),
        'recorded_at' => CarbonImmutable::parse($effectiveAt),
    ]);
    $pool->update(['version' => $seq]);
}

it('shows no expiry entry one millisecond before expires_at and one entry naming the holder at expires_at', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = expiryLogPool();
    $ana = operatorNamed('Ana');
    $start = $clock->now();
    submitAllocation($pool, $ana, 4, extra: ['type' => 'temporary'])->assertCreated();

    $clock->set($start->addHours(24)->subMillisecond());
    expect(kinds(snapshotLog($ana, $pool)))->toBe(['allocation:2', 'restock:1']);

    $clock->set($start->addHours(24));
    $log = snapshotLog($ana, $pool);

    expect(kinds($log))->toBe(['expiry:2', 'allocation:2', 'restock:1'])
        ->and($log[0])->toBe([
            'seq' => null,
            'cursor' => '2x2',
            'kind' => 'expiry',
            'at' => '2026-10-20T10:00:00.000000Z',
            'operator' => ['id' => $ana->id, 'name' => 'Ana'],
            'allocation_seq' => 2,
            'units' => 4,
        ])
        ->and($log[1]['expires_at'])->toBe('2026-10-20T10:00:00.000000Z');
});

it('lists an expiry exactly once over repeated snapshots and over paging by cursor', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = expiryLogPool();
    $ana = operatorNamed('Ana');
    submitAllocation($pool, $ana, 4, extra: ['type' => 'temporary'])->assertCreated();
    $clock->advance('30 hours');

    expect(kinds(snapshotLog($ana, $pool)))->toBe(['expiry:2', 'allocation:2', 'restock:1'])
        ->and(kinds(snapshotLog($ana, $pool)))->toBe(['expiry:2', 'allocation:2', 'restock:1']);

    $first = logOf($ana, $pool, '?limit=1');
    $second = logOf($ana, $pool, '?limit=1&before='.$first['entries'][0]['cursor']);
    $third = logOf($ana, $pool, '?limit=1&before='.$second['entries'][0]['cursor']);

    expect(kinds($first['entries']))->toBe(['expiry:2'])
        ->and($first['has_more'])->toBeTrue()
        ->and(kinds($second['entries']))->toBe(['allocation:2'])
        ->and($second['has_more'])->toBeTrue()
        ->and(kinds($third['entries']))->toBe(['restock:1'])
        ->and($third['has_more'])->toBeFalse();
});

it('derives no expiry for a consumed grant or a zero-grant temporary request', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = expiryLogPool();
    $ana = operatorNamed('Ana');
    submitAllocation($pool, $ana, 100, extra: ['type' => 'consumed'])->assertCreated();
    submitAllocation($pool, operatorNamed('Ben'), 3, extra: ['type' => 'temporary'])
        ->assertCreated()
        ->assertJsonPath('allocation.granted', 0);
    $clock->advance('100 hours');

    expect(kinds(snapshotLog($ana, $pool)))->toBe(['allocation:3', 'allocation:2', 'restock:1']);
});

it('puts the expiry directly above the newest stored entry received at or before it, and below later ones', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = expiryLogPool();
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 4, extra: ['type' => 'temporary'])->assertCreated();
    $clock->advance('1 hour');
    submitAllocation($pool, $ben, 1)->assertCreated();
    $clock->set(CarbonImmutable::parse('2026-10-20 11:00:00 UTC'));
    submitAllocation($pool, $ben, 1)->assertCreated();

    // Ana's window ended at 10:00 on the 20th: after seq 3 (11:00 on the 19th), before seq 4 (11:00 on the 20th).
    expect(kinds(snapshotLog($ben, $pool)))->toBe(['allocation:4', 'expiry:2', 'allocation:3', 'allocation:2', 'restock:1']);
});

it('follows the stored order of entries whose time is slightly out of seq order', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = expiryLogPool();
    $ana = operatorNamed('Ana');
    submitAllocation($pool, $ana, 4, extra: ['type' => 'temporary'])->assertCreated();
    // Seq 3 was received after the expiry; seq 4 committed later but was received before it.
    insertAllocation($pool, $ana, 3, '2026-10-20 10:00:01 UTC');
    insertAllocation($pool, $ana, 4, '2026-10-20 09:59:59 UTC');
    $clock->set(CarbonImmutable::parse('2026-10-21 00:00:00 UTC'));

    expect(kinds(snapshotLog($ana, $pool)))->toBe(['expiry:2', 'allocation:4', 'allocation:3', 'allocation:2', 'restock:1']);
});

it('treats a bare integer before as in M1: only what is older than that seq, an expiry anchored there being newer', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = expiryLogPool();
    $ana = operatorNamed('Ana');
    submitAllocation($pool, $ana, 4, extra: ['type' => 'temporary'])->assertCreated();
    $clock->advance('30 hours');

    expect(kinds(logOf($ana, $pool, '?before=2')['entries']))->toBe(['restock:1'])
        ->and(kinds(logOf($ana, $pool, '?before=3')['entries']))->toBe(['expiry:2', 'allocation:2', 'restock:1']);
});

it('rejects a malformed before cursor with 422', function (string $before) {
    $pool = poolWithRemaining(10);

    $this->getJson("/api/pools/{$pool->id}/log?before={$before}", ['X-Demo-User' => operatorNamed('Ana')->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
})->with(['abc', '5x', 'x5', '1x2x3', '0', '-1', '1.5']);

it('shows the same expiry entry to every viewer', function () {
    $clock = freezeClock('2026-10-19 10:00:00.000000 UTC');
    $pool = expiryLogPool();
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 4, extra: ['type' => 'temporary'])->assertCreated();
    $clock->advance('24 hours');

    expect(snapshotLog($ben, $pool))->toBe(snapshotLog($ana, $pool));
});
