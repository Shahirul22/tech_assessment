<?php

use App\Models\Allocation;
use App\Models\Pool;
use App\Models\Restock;
use App\Models\User;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\LedgerRepository;
use App\Pool\LedgerSequence;
use Illuminate\Http\Client\Pool as HttpPool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * These tests hit the RUNNING stack (nginx -> php-fpm -> postgres) over real HTTP, in parallel, so they use
 * committed data in the stack's own database. They are excluded from `php artisan test`; run them with
 * `APP php artisan test --group=concurrency` after `docker compose up -d --wait`.
 */

/**
 * Point the models at the stack's database. phpunit.xml forces `app_test` for the unit and feature suites.
 */
function useStackDatabase(): void
{
    config(['database.connections.pgsql.database' => env('STACK_DB_DATABASE', 'app')]);
    DB::purge('pgsql');
}

function stackUrl(string $path): string
{
    return rtrim(env('STACK_URL', 'http://nginx'), '/').$path;
}

/**
 * A pool with a restock of $units, committed, so the stack can see it.
 */
function committedPool(int $units): Pool
{
    $pool = Pool::factory()->create(['name' => 'concurrency test', 'version' => 1]);
    Restock::factory()->create(['pool_id' => $pool->id, 'seq' => 1, 'amount' => $units]);

    return $pool;
}

function removePool(Pool $pool): void
{
    DB::table('corrections')->where('pool_id', $pool->id)->delete();
    DB::table('allocations')->where('pool_id', $pool->id)->delete();
    DB::table('restocks')->where('pool_id', $pool->id)->delete();
    DB::table('pools')->where('id', $pool->id)->delete();
}

/**
 * The seeded operators (ids 1 to 3), which the stack's `migrate` service created.
 *
 * @return list<User>
 */
function seededOperators(): array
{
    $operators = User::where('role', 'operator')->orderBy('id')->limit(3)->get()->all();

    expect($operators)->toHaveCount(3, 'The stack database has no seeded operators. Run `docker compose up -d --wait` first.');

    return $operators;
}

/**
 * Fire $count requests of $units at once, all in flight together, round-robin over the operators.
 *
 * @return array<string, Response>
 */
function fireTogether(Pool $pool, int $count, int $units, bool $everySecondTemporary = false): array
{
    $operators = seededOperators();

    $responses = Http::pool(function (HttpPool $http) use ($pool, $count, $units, $operators, $everySecondTemporary) {
        $requests = [];

        foreach (range(0, $count - 1) as $i) {
            $requests[] = $http->as("r{$i}")
                ->withHeaders(['X-Demo-User' => (string) $operators[$i % 3]->id, 'Idempotency-Key' => (string) Str::uuid()])
                ->acceptJson()
                ->timeout(60)
                ->post(stackUrl("/api/pools/{$pool->id}/allocations"), [
                    'requested' => $units,
                    'type' => $everySecondTemporary && $i % 2 === 1 ? 'temporary' : 'consumed',
                ]);
        }

        return $requests;
    }, concurrency: $count);

    foreach ($responses as $name => $response) {
        expect($response)->toBeInstanceOf(Response::class, "Request {$name} did not get a response: ".($response instanceof Throwable ? $response->getMessage() : ''));
    }

    return $responses;
}

beforeEach(function () {
    useStackDatabase();
});

it('keeps the pool exact when 50 parallel requests of 3 hit a pool of 100 (50 parallel x 3 against 100)', function () {
    $pool = committedPool(100);

    try {
        $responses = fireTogether($pool, 50, 3);
        $bodies = collect($responses)->map(fn (Response $r) => $r->json());

        // Every request was answered and stored, including the ones that got nothing.
        expect(collect($responses)->every(fn (Response $r) => $r->status() === 201))->toBeTrue();

        // AC-US5.1: exactly 100 granted, one partial grant of 1, 33 in full, 16 nothing, remaining 0.
        $outcomes = $bodies->countBy('allocation.outcome');
        expect($bodies->sum('allocation.granted'))->toBe(100)
            ->and($outcomes['full'])->toBe(33)
            ->and($outcomes['partial'])->toBe(1)
            ->and($outcomes['none'])->toBe(16)
            ->and($bodies->firstWhere('allocation.outcome', 'partial')['allocation']['granted'])->toBe(1)
            ->and(Allocation::where('pool_id', $pool->id)->sum('granted'))->toBe(100)
            ->and(Allocation::where('pool_id', $pool->id)->count())->toBe(50);

        // AC-US5.2: replay the stored ledger in seq order. Remaining never goes below 0 after any commit,
        // and it equals what the request that created that entry was told.
        $ledger = app(LedgerRepository::class)->load($pool);
        $calculator = app(PoolCalculator::class);
        $toldBySeq = $bodies->mapWithKeys(fn (array $b) => [$b['allocation']['seq'] => $b['pool']['remaining']]);

        $entries = collect($ledger->allocations)->sortBy('seq')->values();
        expect($entries->pluck('seq')->all())->toBe(range(2, 51), 'seq must be gapless and start after the restock');

        foreach ($entries as $entry) {
            $upTo = new Ledger(
                $ledger->restocks,
                $entries->filter(fn ($e) => $e->seq <= $entry->seq)->all(),
            );
            $remaining = $calculator->calculate($upTo)->remaining;

            expect($remaining)->toBeGreaterThanOrEqual(0)
                ->and($toldBySeq[$entry->seq])->toBe($remaining, "seq {$entry->seq}: the response and the ledger disagree");
        }

        expect($calculator->calculate($ledger)->remaining)->toBe(0)
            ->and($pool->fresh()->version)->toBe(51);

        // The commit order is the seq order: recorded_at never goes backwards as seq goes up.
        $recorded = Allocation::where('pool_id', $pool->id)->orderBy('seq')->get()->map(fn ($a) => $a->recorded_at->getTimestampMs())->all();
        $sorted = $recorded;
        sort($sorted);
        expect($recorded)->toBe($sorted);

        // NFR2 / AC-US4.5: p95 of submit-to-confirmed time is at most 1 s.
        $durations = collect($responses)->map(fn (Response $r) => $r->handlerStats()['total_time'])->sort()->values();
        $p95 = $durations[(int) ceil(0.95 * $durations->count()) - 1];
        fwrite(STDERR, sprintf("\n  50 parallel x 3: p50 %.3fs, p95 %.3fs, max %.3fs\n", $durations[24], $p95, $durations->last()));

        expect($p95)->toBeLessThanOrEqual(1.0);
    } finally {
        removePool($pool);
    }
})->group('concurrency');

it('serves two parallel requests of 6 against 10 as 6 in full and 4 partial', function () {
    $pool = committedPool(10);

    try {
        $responses = fireTogether($pool, 2, 6);
        $bodies = collect($responses)->map(fn (Response $r) => $r->json())->sortBy('allocation.seq')->values();

        expect(collect($responses)->every(fn (Response $r) => $r->status() === 201))->toBeTrue()
            // The one committed first is served first: 6 in full, then the 4 left.
            ->and($bodies[0]['allocation']['outcome'])->toBe('full')
            ->and($bodies[0]['allocation']['granted'])->toBe(6)
            ->and($bodies[0]['pool']['remaining'])->toBe(4)
            ->and($bodies[1]['allocation']['outcome'])->toBe('partial')
            ->and($bodies[1]['allocation']['requested'])->toBe(6)
            ->and($bodies[1]['allocation']['granted'])->toBe(4)
            ->and($bodies[1]['pool']['remaining'])->toBe(0)
            ->and(Allocation::where('pool_id', $pool->id)->sum('granted'))->toBe(10);
    } finally {
        removePool($pool);
    }
})->group('concurrency');

it('counts one idempotency key sent 10 times in parallel exactly once', function () {
    $pool = committedPool(100);
    $ana = seededOperators()[0];
    $key = (string) Str::uuid();

    try {
        $responses = Http::pool(function (HttpPool $http) use ($pool, $ana, $key) {
            return array_map(
                fn (int $i) => $http->as("r{$i}")
                    ->withHeaders(['X-Demo-User' => (string) $ana->id, 'Idempotency-Key' => $key])
                    ->acceptJson()->timeout(60)
                    ->post(stackUrl("/api/pools/{$pool->id}/allocations"), ['requested' => 3]),
                range(0, 9),
            );
        }, concurrency: 10);

        foreach ($responses as $name => $response) {
            expect($response)->toBeInstanceOf(Response::class, "Request {$name} did not get a response");
        }

        $statuses = collect($responses)->map(fn (Response $r) => $r->status());
        $bodies = collect($responses)->map(fn (Response $r) => $r->json());

        // The lookup happens inside the pool lock, so exactly one request creates the entry and nine replay it.
        expect($statuses->filter(fn ($s) => $s === 201))->toHaveCount(1)
            ->and($statuses->filter(fn ($s) => $s === 200))->toHaveCount(9)
            ->and(collect($responses)->filter(fn (Response $r) => $r->status() === 200)
                ->every(fn (Response $r) => $r->header('Idempotent-Replayed') === 'true'))->toBeTrue()
            // Every reply carries the same result.
            ->and($bodies->unique(fn (array $b) => json_encode($b)))->toHaveCount(1)
            ->and($bodies->first()['allocation']['granted'])->toBe(3)
            ->and($bodies->first()['pool']['remaining'])->toBe(97)
            // One stored allocation, remaining reduced once.
            ->and(Allocation::where('pool_id', $pool->id)->count())->toBe(1)
            ->and(app(PoolCalculator::class)->calculate(app(LedgerRepository::class)->load($pool))->remaining)->toBe(97)
            ->and($pool->fresh()->version)->toBe(2);
    } finally {
        removePool($pool);
    }
})->group('concurrency');

// (added) The 503 pool_busy path of plan §6, which no other test reaches.
it('answers 503 pool_busy with Retry-After when the pool lock cannot be taken in time, and records nothing', function () {
    $pool = committedPool(10);
    $ana = seededOperators()[0];

    // A second connection holds the pool's row lock, as a slow writer would.
    config(['database.connections.lock_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('lock_holder');
    $holder->beginTransaction();
    $holder->table('pools')->where('id', $pool->id)->lockForUpdate()->first();

    try {
        $startedAt = microtime(true);
        $blocked = Http::withHeaders(['X-Demo-User' => (string) $ana->id, 'Idempotency-Key' => (string) Str::uuid()])
            ->acceptJson()->timeout(30)
            ->post(stackUrl("/api/pools/{$pool->id}/allocations"), ['requested' => 3]);
        $waited = microtime(true) - $startedAt;

        expect($blocked->status())->toBe(503)
            ->and($blocked->json('error.code'))->toBe('pool_busy')
            ->and($blocked->header('Retry-After'))->toBe('1')
            // lock_timeout is 5 s: it waited for the lock, it did not fail at once.
            ->and($waited)->toBeGreaterThan(4.5)
            ->and(Allocation::where('pool_id', $pool->id)->count())->toBe(0);

        // Once the lock is free the same request works.
        $holder->rollBack();

        $retry = Http::withHeaders(['X-Demo-User' => (string) $ana->id, 'Idempotency-Key' => (string) Str::uuid()])
            ->acceptJson()->timeout(30)
            ->post(stackUrl("/api/pools/{$pool->id}/allocations"), ['requested' => 3]);

        expect($retry->status())->toBe(201)
            ->and($retry->json('pool.remaining'))->toBe(7);
    } finally {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        DB::purge('lock_holder');
        removePool($pool);
    }
})->group('concurrency');

// (added) A snapshot read while writers are active must still agree with itself.
it('returns self-consistent snapshots while allocations are being written', function () {
    $pool = committedPool(100);
    $operators = seededOperators();

    try {
        $responses = Http::pool(function (HttpPool $http) use ($pool, $operators) {
            $requests = [];

            foreach (range(0, 39) as $i) {
                $headers = ['X-Demo-User' => (string) $operators[$i % 3]->id];
                $requests[] = $http->as("write{$i}")->withHeaders($headers + ['Idempotency-Key' => (string) Str::uuid()])
                    ->acceptJson()->timeout(60)->post(stackUrl("/api/pools/{$pool->id}/allocations"), ['requested' => 3]);
                $requests[] = $http->as("read{$i}")->withHeaders($headers)
                    ->acceptJson()->timeout(60)->get(stackUrl("/api/pools/{$pool->id}"));
            }

            return $requests;
        }, concurrency: 80);

        $snapshots = collect($responses)->filter(fn ($r, $name) => str_starts_with($name, 'read'))->map(fn (Response $r) => $r->json());
        expect($snapshots)->toHaveCount(40);

        foreach ($snapshots as $name => $snapshot) {
            $seqs = array_column($snapshot['log'], 'seq');
            $granted = collect($snapshot['log'])->where('kind', 'allocation')->sum('granted');

            // Version, log and remaining come from one moment: the log is gapless up to the version, and
            // remaining is exactly the restock minus what that log granted.
            expect($snapshot['version'])->toBe(max($seqs), "{$name}: version is not the highest log seq")
                ->and($seqs)->toBe(range($snapshot['version'], 1), "{$name}: the log has a gap or an entry newer than the version")
                ->and($snapshot['remaining'])->toBe(100 - $granted, "{$name}: remaining does not match the log");
        }
    } finally {
        removePool($pool);
    }
})->group('concurrency');

// (added) The shared sequence refuses to run outside the pool's transaction.
it('refuses to hand out a ledger number outside a transaction, and changes nothing', function () {
    $pool = committedPool(10);

    try {
        expect(fn () => app(LedgerSequence::class)->next($pool))->toThrow(LogicException::class, 'transaction');
        expect($pool->fresh()->version)->toBe(1);
    } finally {
        removePool($pool);
    }
})->group('concurrency');

// (added, M2) The same hard rule with Temporary requests mixed in: nothing expires inside the test (windows are 24 h or more).
it('keeps the pool exact when 50 parallel requests of 3 are half temporary (50 parallel x 3, mixed types, against 100)', function () {
    $pool = committedPool(100);

    try {
        $responses = fireTogether($pool, 50, 3, everySecondTemporary: true);
        $bodies = collect($responses)->map(fn (Response $r) => $r->json());

        expect(collect($responses)->every(fn (Response $r) => $r->status() === 201))->toBeTrue();

        $outcomes = $bodies->countBy('allocation.outcome');
        expect($bodies->sum('allocation.granted'))->toBe(100)
            ->and($outcomes['full'])->toBe(33)
            ->and($outcomes['partial'])->toBe(1)
            ->and($outcomes['none'])->toBe(16)
            ->and($bodies->firstWhere('allocation.outcome', 'partial')['allocation']['granted'])->toBe(1)
            ->and(Allocation::where('pool_id', $pool->id)->sum('granted'))->toBe(100)
            ->and(Allocation::where('pool_id', $pool->id)->count())->toBe(50)
            ->and(Allocation::where('pool_id', $pool->id)->where('type', 'temporary')->count())->toBe(25);

        $ledger = app(LedgerRepository::class)->load($pool);
        $calculator = app(PoolCalculator::class);
        $toldBySeq = $bodies->mapWithKeys(fn (array $b) => [$b['allocation']['seq'] => $b['pool']['remaining']]);
        $entries = collect($ledger->allocations)->sortBy('seq')->values();

        foreach ($entries as $entry) {
            $upTo = new Ledger($ledger->restocks, $entries->filter(fn ($e) => $e->seq <= $entry->seq)->all());
            $remaining = $calculator->calculate($upTo)->remaining;

            expect($remaining)->toBeGreaterThanOrEqual(0)
                ->and($toldBySeq[$entry->seq])->toBe($remaining, "seq {$entry->seq}: the response and the ledger disagree");
        }

        expect($calculator->calculate($ledger)->remaining)->toBe(0)
            ->and($pool->fresh()->version)->toBe(51);

        $durations = collect($responses)->map(fn (Response $r) => $r->handlerStats()['total_time'])->sort()->values();
        $p95 = $durations[(int) ceil(0.95 * $durations->count()) - 1];
        fwrite(STDERR, sprintf("\n  50 parallel x 3, half temporary: p50 %.3fs, p95 %.3fs, max %.3fs\n", $durations[24], $p95, $durations->last()));

        expect($p95)->toBeLessThanOrEqual(1.0);
    } finally {
        removePool($pool);
    }
})->group('concurrency');
