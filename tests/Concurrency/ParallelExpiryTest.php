<?php

use App\Models\Allocation;
use App\Models\Pool;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Config\RulesConfig;
use App\Pool\LedgerRepository;
use App\Pool\LedgerSequence;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool as HttpPool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * (added) Requests that straddle an expiry, against the running stack (NFR1, FR5.9, DD2). Like the other tests in this
 * folder it uses committed data and is run with `APP php artisan test --group=concurrency`.
 *
 * Fixture: a pool of 100, fully taken by a Temporary 40 that expires about 3 seconds into the test and a Consumed 60.
 * Nothing is free until the Temporary expires, then 40 units are.
 */

/**
 * @return array{0: Pool, 1: CarbonImmutable} The pool, and the instant the Temporary 40 expires.
 */
function poolWithExpiryAboutToHappen(): array
{
    $pool = committedPool(100);
    $operator = seededOperators()[0];
    $sequence = app(LedgerSequence::class);
    $rules = app(RulesConfig::class);

    $effectiveAt = CarbonImmutable::now()->subSeconds($rules->windowSeconds('bronze') - 3);
    $expiresAt = $rules->expiresAt('bronze', $effectiveAt);

    DB::transaction(function () use ($pool, $operator, $sequence, $effectiveAt) {
        foreach ([[40, 'temporary', $effectiveAt], [60, 'consumed', CarbonImmutable::now()->subHour()]] as [$units, $type, $at]) {
            Allocation::factory()->create([
                'pool_id' => $pool->id,
                'seq' => $sequence->next($pool),
                'operator_id' => $operator->id,
                'type' => $type,
                'requested' => $units,
                'granted' => $units,
                'tier_at_log' => 'bronze',
                'effective_at' => $at,
                'recorded_at' => CarbonImmutable::now(),
            ]);
        }
    });

    return [$pool, $expiresAt];
}

/**
 * 10 waves of 5 requests of 3 units, one wave about every 600 ms.
 *
 * @return list<Response>
 */
function fireInWaves(Pool $pool): array
{
    $operators = seededOperators();
    $startedAt = microtime(true);
    $responses = [];

    foreach (range(0, 9) as $wave) {
        $wait = $startedAt + $wave * 0.6 - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        $batch = Http::pool(function (HttpPool $http) use ($pool, $operators, $wave) {
            return array_map(
                fn (int $i) => $http->as("w{$wave}r{$i}")
                    ->withHeaders(['X-Demo-User' => (string) $operators[$i % 3]->id, 'Idempotency-Key' => (string) Str::uuid()])
                    ->acceptJson()->timeout(60)
                    ->post(stackUrl("/api/pools/{$pool->id}/allocations"), ['requested' => 3]),
                range(0, 4),
            );
        }, concurrency: 5);

        foreach ($batch as $name => $response) {
            expect($response)->toBeInstanceOf(Response::class, "Request {$name} did not get a response");
            $responses[] = $response;
        }
    }

    return $responses;
}

beforeEach(function () {
    useStackDatabase();
});

it('keeps every reply consistent with the ledger when requests land before and after an expiry', function () {
    [$pool, $expiresAt] = poolWithExpiryAboutToHappen();

    try {
        $responses = fireInWaves($pool);

        expect($responses)->toHaveCount(50)
            ->and(collect($responses)->every(fn (Response $r) => $r->status() === 201))->toBeTrue();

        $bodies = collect($responses)->map(fn (Response $r) => $r->json());

        // No reply ever shows a negative pool.
        expect($bodies->every(fn (array $b) => $b['pool']['remaining'] >= 0))->toBeTrue();

        // Replay the ledger in seq order. Each entry is evaluated at the newest effective_at seen so far, and that
        // must reproduce what its request was told (DD2).
        $ledger = app(LedgerRepository::class)->load($pool);
        $calculator = app(PoolCalculator::class);
        $entries = collect($ledger->allocations)->sortBy('seq')->values();
        $toldBySeq = $bodies->mapWithKeys(fn (array $b) => [$b['allocation']['seq'] => $b]);

        expect($entries)->toHaveCount(52);

        $instant = null;
        $grantedBeforeExpiry = 0;
        $grantedAfterExpiry = 0;
        $receivedBefore = 0;
        $receivedAfter = 0;

        foreach ($entries as $entry) {
            $instant = $instant === null || $entry->effectiveAt->greaterThan($instant) ? $entry->effectiveAt : $instant;
            $asOf = new Ledger(
                $ledger->restocks,
                $entries->filter(fn ($e) => $e->seq <= $entry->seq)->all(),
            );

            $told = $toldBySeq[$entry->seq] ?? null;

            if ($told === null) {
                continue; // The two fixture rows were inserted directly.
            }

            expect($told['pool']['remaining'])->toBe($calculator->calculateAt($asOf, $instant)->remaining, "seq {$entry->seq}: the reply and the ledger disagree");

            if ($entry->effectiveAt->lessThan($expiresAt)) {
                $receivedBefore++;
            } else {
                $receivedAfter++;
            }

            // Units can only be granted once the evaluation instant has reached the expiry.
            if ($entry->units > 0) {
                expect($instant->greaterThanOrEqualTo($expiresAt))->toBeTrue("seq {$entry->seq} was granted units before the expiry");
                $entry->effectiveAt->lessThan($expiresAt) ? $grantedBeforeExpiry += $entry->units : $grantedAfterExpiry += $entry->units;
            }
        }

        $granted = Allocation::where('pool_id', $pool->id)->where('seq', '>', 3)->sum('granted');

        // Only the 40 units of the expired Temporary were ever free.
        expect($granted)->toBeLessThanOrEqual(40)
            ->and($grantedAfterExpiry)->toBeGreaterThan(0)
            ->and($receivedBefore)->toBeGreaterThan(0, 'no request arrived before the expiry; the timing of the test is off')
            ->and($receivedAfter)->toBeGreaterThan(0, 'no request arrived after the expiry; the timing of the test is off')
            ->and($calculator->calculateAt($ledger, $instant)->remaining)->toBe(40 - $granted)
            ->and($pool->fresh()->version)->toBe(53);

        fwrite(STDERR, sprintf("\n  expiry straddle: %d received before, %d after; granted %d (%d from requests received before the expiry)\n", $receivedBefore, $receivedAfter, $granted, $grantedBeforeExpiry));
    } finally {
        removePool($pool);
    }
})->group('concurrency');
