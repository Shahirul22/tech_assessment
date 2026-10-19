<?php

use App\Models\Pool;
use App\Models\Restock;
use App\Models\User;
use App\Support\Clock;
use App\Support\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
