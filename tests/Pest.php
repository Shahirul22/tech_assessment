<?php

use App\Models\Allocation;
use App\Models\Correction;
use App\Models\Pool;
use App\Models\Restock;
use App\Models\User;
use App\Pool\AddRestock;
use App\Pool\AllocationService;
use App\Pool\ApplyCorrection;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Calculation\PoolState;
use App\Pool\CorrectionService;
use App\Pool\LedgerRepository;
use App\Pool\RestockService;
use App\Pool\SubmitAllocation;
use App\Support\Clock;
use App\Support\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests run against the live stack with committed data, so they get the app but not RefreshDatabase.
pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A pool whose ledger holds one restock of $units at seq 1, so `remaining` is $units and `version` is 1.
 */
function poolWithRemaining(int $units): Pool
{
    $pool = Pool::factory()->create(['version' => 1]);
    Restock::factory()->create(['pool_id' => $pool->id, 'seq' => 1, 'amount' => $units]);

    return $pool;
}

function operatorNamed(string $name, string $tier = 'bronze'): User
{
    return User::factory()->operator($tier)->create(['name' => $name]);
}

/**
 * The PRD §7 pool: capacity 100 at 08:00 (seq 1), Ana (Bronze) Consumed 40 at 09:00, Ben (Silver) Consumed 50 at 10:00 and
 * Cara (Gold) Consumed 10 at 11:00 (seq 2-4), so `remaining` is 0 and `version` is 4. Dana is the supervisor.
 * Freeze the clock after 11:00 on 2026-10-17 before calling a writer.
 *
 * @return array{pool: Pool, ana: User, ben: User, cara: User, dana: User, allocations: array{ana: Allocation, ben: Allocation, cara: Allocation}}
 */
function workedPool(): array
{
    $pool = Pool::factory()->create(['version' => 4]);
    DB::table('restocks')->insert(['pool_id' => $pool->id, 'seq' => 1, 'amount' => 100, 'recorded_at' => '2026-10-17 08:00:00+00']);

    $ana = operatorNamed('Ana', 'bronze');
    $ben = operatorNamed('Ben', 'silver');
    $cara = operatorNamed('Cara', 'gold');
    $allocate = fn (int $seq, User $operator, int $units, string $at) => Allocation::factory()->create([
        'pool_id' => $pool->id, 'seq' => $seq, 'operator_id' => $operator->id, 'type' => 'consumed', 'requested' => $units,
        'granted' => $units, 'tier_at_log' => $operator->tier, 'effective_at' => CarbonImmutable::parse("2026-10-17 {$at} UTC"),
        'recorded_at' => CarbonImmutable::parse("2026-10-17 {$at} UTC"),
    ]);

    return [
        'pool' => $pool, 'ana' => $ana, 'ben' => $ben, 'cara' => $cara,
        'dana' => User::factory()->supervisor()->create(['name' => 'Dana']),
        'allocations' => ['ana' => $allocate(2, $ana, 40, '09:00:00'), 'ben' => $allocate(3, $ben, 50, '10:00:00'), 'cara' => $allocate(4, $cara, 10, '11:00:00')],
    ];
}

const EXPIRY_T = '2026-10-17 09:00:00.000000 UTC';

/**
 * A pool of $capacity (restocked at 08:00), Ana (Bronze, 24 h) with a Temporary $units logged at T, and Dana.
 *
 * @return array{pool: Pool, ana: User, dana: User, clock: FrozenClock}
 */
function expiryWorld(int $capacity, int $units = 4): array
{
    $clock = freezeClock(EXPIRY_T);
    $pool = Pool::factory()->create(['version' => 1]);
    DB::table('restocks')->insert(['pool_id' => $pool->id, 'seq' => 1, 'amount' => $capacity, 'recorded_at' => '2026-10-17 08:00:00+00']);
    $ana = operatorNamed('Ana', 'bronze');

    app(AllocationService::class)->submit($pool, $ana, new SubmitAllocation($units, 'temporary', (string) Str::uuid()));

    return ['pool' => $pool, 'ana' => $ana, 'dana' => User::factory()->supervisor()->create(['name' => 'Dana']), 'clock' => $clock];
}

function expiryCorrect(array $world, string $kind, ?int $amount = null)
{
    return app(CorrectionService::class)->apply($world['pool'], $world['dana'], new ApplyCorrection(2, $kind, $amount, 'Counted wrong', (string) Str::uuid()));
}

/** The pool as the stored ledger gives it at T plus $offset. */
function expiryStateAt(array $world, string $offset): PoolState
{
    $ledger = app(LedgerRepository::class)->load($world['pool']->fresh());

    return app(PoolCalculator::class)->calculateAt($ledger, CarbonImmutable::parse(EXPIRY_T)->modify($offset));
}

/** Dana corrects the entry with the given seq. $amount null with kind 'void'. */
function correct(array $world, int $allocationSeq, string $kind, ?int $amount = null, string $reason = 'Counted wrong', ?string $key = null, ?Pool $pool = null)
{
    return app(CorrectionService::class)->apply(
        $pool ?? $world['pool'],
        $world['dana'],
        new ApplyCorrection($allocationSeq, $kind, $amount, $reason, $key ?? (string) Str::uuid()),
    );
}

function restockUnits(array $world, int $amount)
{
    return app(RestockService::class)->add($world['pool'], $world['dana'], new AddRestock($amount, null, (string) Str::uuid()));
}

function requestUnits(array $world, $operator, int $requested)
{
    return app(AllocationService::class)->submit($world['pool'], $operator, new SubmitAllocation($requested, 'consumed', (string) Str::uuid()));
}

function correctionCount(array $world): int
{
    return Correction::where('pool_id', $world['pool']->id)->count();
}

/**
 * Bind a frozen clock into the container, so the server's "now" is known to the test.
 */
function freezeClock(string $at = '2026-10-17 10:00:00.123456 UTC'): FrozenClock
{
    $clock = new FrozenClock(CarbonImmutable::parse($at));
    test()->instance(Clock::class, $clock);

    return $clock;
}

/**
 * POST an allocation as $user. A fresh Idempotency-Key is used unless one is given.
 *
 * @param  array<string, mixed>  $extra  more body fields, for example ['type' => 'temporary']
 * @param  array<string, string>  $headers  more headers. A null $user sends no X-Demo-User.
 */
function submitAllocation(Pool $pool, ?User $user, mixed $requested, ?string $key = null, array $extra = [], array $headers = []): TestResponse
{
    $body = $requested === null ? $extra : ['requested' => $requested] + $extra;

    return test()->postJson("/api/pools/{$pool->id}/allocations", $body, array_filter([
        'X-Demo-User' => $user?->id,
        'Idempotency-Key' => $key ?? (string) Str::uuid(),
    ], fn ($value) => $value !== null) + $headers);
}
