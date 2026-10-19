<?php

use App\Models\Allocation;
use App\Models\Pool;
use App\Models\User;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\LedgerRepository;
use Illuminate\Http\Client\Pool as HttpPool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * (added, M3) Corrections and restocks racing operator requests, against the running stack (NFR1, NFR2, FR6.10).
 * Like the other tests in this folder it uses committed data and is run with `APP php artisan test --group=concurrency`.
 * It reuses the helpers of ParallelAllocationTest.php, which Pest loads first.
 */

function seededSupervisor(): User
{
    $supervisor = User::where('role', 'supervisor')->orderBy('id')->first();

    expect($supervisor)->not->toBeNull('The stack database has no seeded supervisor. Run `docker compose up -d --wait` first.');

    return $supervisor;
}

/**
 * A committed pool of 100 where the given operators already hold units: Consumed entries at seq 2, 3, ...
 *
 * @param  list<array{0: User, 1: int}>  $holdings
 */
function committedPoolWithHoldings(array $holdings): Pool
{
    $pool = committedPool(100);

    foreach ($holdings as $i => [$operator, $units]) {
        Allocation::factory()->create([
            'pool_id' => $pool->id, 'seq' => $i + 2, 'operator_id' => $operator->id, 'type' => 'consumed', 'requested' => $units,
            'granted' => $units, 'tier_at_log' => $operator->tier, 'effective_at' => now()->subMinutes(10 - $i), 'recorded_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $pool->update(['version' => count($holdings) + 1]);

    return $pool;
}

/** @return array<string, string> */
function writeHeaders(User $user): array
{
    return ['X-Demo-User' => (string) $user->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/**
 * The remaining the stored ledger gives right after entry $seq, with every entry up to it.
 */
function remainingAfter(Pool $pool, int $seq): int
{
    $ledger = app(LedgerRepository::class)->load($pool);

    return app(PoolCalculator::class)->calculateAt($ledger->asOf($seq), $ledger->instantAt($seq) ?? now()->toImmutable())->remaining;
}

function p95(array $durations): float
{
    sort($durations);

    return $durations[(int) ceil(0.95 * count($durations)) - 1];
}

beforeEach(function () {
    useStackDatabase();
});

it('lets exactly one of a raise needing the last free units and a request for them win, in every one of 20 rounds', function () {
    [$ana, $ben] = seededOperators();
    $dana = seededSupervisor();
    $correctionWon = 0;
    $requestWon = 0;

    foreach (range(1, 20) as $round) {
        // Ana holds 40 and Ben 40, so 20 are free: exactly what the raise needs and what Ben asks for.
        $pool = committedPoolWithHoldings([[$ana, 40], [$ben, 40]]);

        try {
            $responses = Http::pool(function (HttpPool $http) use ($pool, $dana, $ben) {
                return [
                    $http->as('correction')->withHeaders(writeHeaders($dana))->acceptJson()->timeout(60)
                        ->post(stackUrl("/api/pools/{$pool->id}/allocations/2/corrections"), ['kind' => 'amend', 'amount' => 60, 'reason' => 'Miscounted']),
                    $http->as('request')->withHeaders(writeHeaders($ben))->acceptJson()->timeout(60)
                        ->post(stackUrl("/api/pools/{$pool->id}/allocations"), ['requested' => 20]),
                ];
            }, concurrency: 2);

            $correction = $responses['correction'];
            $request = $responses['request'];

            expect($correction)->toBeInstanceOf(Response::class)
                ->and($request)->toBeInstanceOf(Response::class)
                ->and($request->status())->toBe(201);

            $granted = $request->json('allocation.granted');

            if ($correction->status() === 201) {
                // The raise took the 20; Ben got nothing.
                $correctionWon++;
                expect($granted)->toBe(0)
                    ->and($correction->json('pool.remaining'))->toBeGreaterThanOrEqual(0);
            } else {
                // Ben took the 20; the raise was refused with nothing free.
                $requestWon++;
                expect($correction->status())->toBe(422)
                    ->and($correction->json('error.code'))->toBe('insufficient_free_units')
                    ->and($granted)->toBe(20)
                    ->and(DB::table('corrections')->where('pool_id', $pool->id)->count())->toBe(0);
            }

            $ledger = app(LedgerRepository::class)->load($pool);
            $calculator = app(PoolCalculator::class);

            // Never below 0, never both, and every reply equals the ledger replayed up to its entry.
            expect($calculator->calculate($ledger)->remaining)->toBe(0)
                ->and($request->json('pool.remaining'))->toBe(remainingAfter($pool, $request->json('allocation.seq')));

            if ($correction->status() === 201) {
                expect($correction->json('pool.remaining'))->toBe(remainingAfter($pool, $correction->json('correction.seq')));
            }
        } finally {
            removePool($pool);
        }
    }

    fwrite(STDERR, sprintf("\n  raise vs request: correction won %d, request won %d of 20\n", $correctionWon, $requestWon));
})->group('concurrency');

it('keeps the pool exact when 50 requests of 3 race a lowering that frees 20 and a restock of 10', function () {
    $operators = seededOperators();
    $dana = seededSupervisor();
    // Ana holds 30 of 100, so 70 are free to start with.
    $pool = committedPoolWithHoldings([[$operators[0], 30]]);

    try {
        $responses = Http::pool(function (HttpPool $http) use ($pool, $operators, $dana) {
            $requests = [];

            foreach (range(0, 49) as $i) {
                $requests[] = $http->as("r{$i}")->withHeaders(writeHeaders($operators[$i % 3]))->acceptJson()->timeout(60)
                    ->post(stackUrl("/api/pools/{$pool->id}/allocations"), ['requested' => 3]);

                // Sent in the middle of the stream.
                if ($i === 24) {
                    $requests[] = $http->as('lowering')->withHeaders(writeHeaders($dana))->acceptJson()->timeout(60)
                        ->post(stackUrl("/api/pools/{$pool->id}/allocations/2/corrections"), ['kind' => 'amend', 'amount' => 10, 'reason' => 'Counted wrong']);
                    $requests[] = $http->as('restock')->withHeaders(writeHeaders($dana))->acceptJson()->timeout(60)
                        ->post(stackUrl("/api/pools/{$pool->id}/restocks"), ['amount' => 10, 'note' => 'Delivery']);
                }
            }

            return $requests;
        }, concurrency: 52);

        foreach ($responses as $name => $response) {
            expect($response)->toBeInstanceOf(Response::class, "Request {$name} did not get a response")
                ->and($response->status())->toBe(201, "{$name}: ".$response->body());
        }

        // Total ever granted never exceeds capacity plus what was freed and restocked (Ana's original 30 counts as granted).
        $granted = Allocation::where('pool_id', $pool->id)->sum('granted');
        expect($granted)->toBeLessThanOrEqual(100 + 20 + 10);

        // seq is unique and gapless across the three tables: 1 seed, Ana, 50 requests, 1 correction, 1 restock.
        $seqs = collect()
            ->merge(DB::table('restocks')->where('pool_id', $pool->id)->pluck('seq'))
            ->merge(DB::table('allocations')->where('pool_id', $pool->id)->pluck('seq'))
            ->merge(DB::table('corrections')->where('pool_id', $pool->id)->pluck('seq'))
            ->sort()->values()->all();
        expect($seqs)->toBe(range(1, 54))
            ->and($pool->fresh()->version)->toBe(54);

        // After every commit remaining is at least 0, and each reply equals the ledger replayed up to its own entry.
        $ledger = app(LedgerRepository::class)->load($pool);
        $told = [];

        foreach ($responses as $name => $response) {
            $seq = $response->json('allocation.seq') ?? $response->json('restock.seq') ?? $response->json('correction.seq');
            $told[$seq] = [$name, $response->json('pool.remaining')];
        }

        foreach (range(2, 54) as $seq) {
            $remaining = remainingAfter($pool, $seq);

            expect($remaining)->toBeGreaterThanOrEqual(0, "after seq {$seq}");

            if (isset($told[$seq])) {
                expect($told[$seq][1])->toBe($remaining, "seq {$seq} ({$told[$seq][0]}): the reply and the ledger disagree");
            }
        }

        // Log order is commit order: what an allocation or a correction recorded under the lock never goes backwards by seq.
        $recorded = collect()
            ->merge(DB::table('allocations')->where('pool_id', $pool->id)->get(['seq', 'recorded_at']))
            ->merge(DB::table('corrections')->where('pool_id', $pool->id)->get(['seq', 'recorded_at']))
            ->sortBy('seq')->map(fn ($row) => strtotime($row->recorded_at.'') * 1000 + (int) substr(explode('.', $row->recorded_at)[1] ?? '0', 0, 3))->values()->all();
        $sorted = $recorded;
        sort($sorted);
        expect($recorded)->toBe($sorted);

        $log = Http::withHeaders(['X-Demo-User' => (string) $operators[0]->id])->acceptJson()->get(stackUrl("/api/pools/{$pool->id}"))->json('log');
        expect(array_column($log, 'seq'))->toBe(range(54, 1));

        // NFR2: p95 of submit-to-confirmed is at most 1 s for operator requests, the restock and the correction.
        $times = collect($responses)->map(fn (Response $r) => $r->handlerStats()['total_time']);
        $p95All = p95($times->all());
        fwrite(STDERR, sprintf(
            "\n  50 x 3 with a lowering and a restock: p95 %.3fs (requests %.3fs, restock %.3fs, correction %.3fs)\n",
            $p95All,
            p95($times->only(array_map(fn ($i) => "r{$i}", range(0, 49)))->all()),
            $times['restock'],
            $times['lowering'],
        ));

        expect($p95All)->toBeLessThanOrEqual(1.0)
            ->and($times['restock'])->toBeLessThanOrEqual(1.0)
            ->and($times['lowering'])->toBeLessThanOrEqual(1.0);
    } finally {
        removePool($pool);
    }
})->group('concurrency');

it('applies two supervisors lowering the same entry at once one at a time', function () {
    $ana = seededOperators()[0];
    $dana = seededSupervisor();
    $eli = User::factory()->supervisor()->create(['name' => 'Eli (concurrency test)']);
    $pool = committedPoolWithHoldings([[$ana, 40]]);

    try {
        $responses = Http::pool(function (HttpPool $http) use ($pool, $dana, $eli) {
            return array_map(
                fn (string $name, User $supervisor) => $http->as($name)->withHeaders(writeHeaders($supervisor))->acceptJson()->timeout(60)
                    ->post(stackUrl("/api/pools/{$pool->id}/allocations/2/corrections"), ['kind' => 'amend', 'amount' => 20, 'reason' => 'Counted wrong']),
                ['dana', 'eli'],
                [$dana, $eli],
            );
        }, concurrency: 2);

        $statuses = collect($responses)->map(fn (Response $r) => $r->status())->sort()->values()->all();
        $refused = collect($responses)->first(fn (Response $r) => $r->status() === 422);

        expect($statuses)->toBe([201, 422])
            ->and($refused->json('error.code'))->toBe('validation_failed')
            ->and($refused->json('error.details.fields.amount'))->toBe(['no_change'])
            ->and(DB::table('corrections')->where('pool_id', $pool->id)->count())->toBe(1)
            ->and(app(PoolCalculator::class)->calculate(app(LedgerRepository::class)->load($pool))->remaining)->toBe(80)
            ->and($pool->fresh()->version)->toBe(3);
    } finally {
        removePool($pool);
        $eli->delete();
    }
})->group('concurrency');
