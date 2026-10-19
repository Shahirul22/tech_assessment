<?php

use App\Events\PoolUpdated;
use App\Models\Allocation;
use App\Pool\AddRestock;
use App\Pool\AllocationService;
use App\Pool\Exceptions\CorrectionRefused;
use App\Pool\RestockService;
use App\Pool\SubmitAllocation;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Run deferred callbacks at once, so the test can see what would run after the response.
    $this->withoutDefer();
});

it('broadcasts the new version, remaining and entry after a successful submit', function () {
    Event::fake([PoolUpdated::class]);
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    $response = submitAllocation($pool, $ana, 4)->assertCreated();

    Event::assertDispatchedTimes(PoolUpdated::class, 1);
    Event::assertDispatched(PoolUpdated::class, function (PoolUpdated $event) use ($pool, $ana, $response) {
        return $event->poolId === $pool->id
            && $event->version === 2
            && $event->remaining === 6
            && $event->broadcastOn()[0]->name === "pool.{$pool->id}"
            // The entry is the same log entry the log endpoint would show.
            && $event->entry === [
                'seq' => 2,
                'cursor' => '2',
                'kind' => 'allocation',
                'at' => $response->json('allocation.effective_at'),
                'operator' => ['id' => $ana->id, 'name' => 'Ana'],
                'type' => 'consumed',
                'requested' => 4,
                'granted' => 4,
                'outcome' => 'full',
                'expires_at' => null,
                'corrected_to' => null,
                'voided' => false,
            ];
    });
});

it('broadcasts a zero grant too, so every screen sees it', function () {
    Event::fake([PoolUpdated::class]);
    $pool = poolWithRemaining(1);
    submitAllocation($pool, operatorNamed('Ana'), 1)->assertCreated();

    submitAllocation($pool, operatorNamed('Ben'), 3)->assertCreated()->assertJsonPath('allocation.outcome', 'none');

    Event::assertDispatchedTimes(PoolUpdated::class, 2);
    Event::assertDispatched(PoolUpdated::class, fn (PoolUpdated $e) => $e->version === 3 && $e->remaining === 0
        && $e->entry['granted'] === 0 && $e->entry['outcome'] === 'none');
});

it('does not broadcast a replay, a conflict or a validation error', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $ben = operatorNamed('Ben');
    submitAllocation($pool, $ana, 3, 'key-1')->assertCreated();

    Event::fake([PoolUpdated::class]);

    submitAllocation($pool, $ana, 3, 'key-1')->assertOk();                  // replay
    submitAllocation($pool, $ben, 3, 'key-1')->assertStatus(409);           // conflict
    submitAllocation($pool, $ana, 0)->assertStatus(422);                    // validation
    submitAllocation($pool, $ana, 'abc')->assertStatus(422);                // validation
    $this->postJson("/api/pools/{$pool->id}/allocations", ['requested' => 1], ['X-Demo-User' => $ana->id])->assertStatus(400); // no key

    Event::assertNotDispatched(PoolUpdated::class);
    expect(Allocation::count())->toBe(1);
});

it('broadcasts after the transaction has closed, so the pool lock is already released', function () {
    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');
    $levels = [];
    $baseline = DB::transactionLevel();

    Event::listen(PoolUpdated::class, function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });

    submitAllocation($pool, $ana, 1)->assertCreated();

    // The only transaction open is the test harness's own. The service's transaction (which holds the lock) is over.
    expect($levels)->toBe([$baseline]);
});

it('still returns 201 when the broadcaster throws, and reports the failure', function () {
    Exceptions::fake();
    Broadcast::extend('throwing', fn () => new class extends Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new RuntimeException('reverb is down');
        }
    });
    config(['broadcasting.default' => 'throwing', 'broadcasting.connections.throwing' => ['driver' => 'throwing']]);

    $pool = poolWithRemaining(10);
    $ana = operatorNamed('Ana');

    submitAllocation($pool, $ana, 4)
        ->assertCreated()
        ->assertJsonPath('allocation.granted', 4)
        ->assertJsonPath('pool.remaining', 6);

    expect(Allocation::sole()->granted)->toBe(4);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'reverb is down');
});

it('defers the broadcast until after the response when deferral is on', function () {
    $this->withDefer();
    Event::fake([PoolUpdated::class]);
    $pool = poolWithRemaining(10);

    // The deferred callback is queued, not run, while the service returns.
    $deferred = app(DeferredCallbackCollection::class);
    $before = count($deferred);

    app(AllocationService::class)->submit(
        $pool,
        operatorNamed('Ana'),
        new SubmitAllocation(2, 'consumed', 'key-defer'),
    );

    expect(count($deferred))->toBe($before + 1);
    Event::assertNotDispatched(PoolUpdated::class);

    $deferred->invoke();

    Event::assertDispatchedTimes(PoolUpdated::class, 1);
});

it('broadcasts a restock with its version, the new remaining and a restock entry', function () {
    freezeClock('2026-10-17 12:00:00 UTC');
    Event::fake([PoolUpdated::class]);
    $world = workedPool();

    restockUnits($world, 50);

    Event::assertDispatchedTimes(PoolUpdated::class, 1);
    Event::assertDispatched(PoolUpdated::class, fn (PoolUpdated $event) => $event->poolId === $world['pool']->id
        && $event->version === 5
        && $event->remaining === 50
        && $event->entry === [
            'seq' => 5, 'cursor' => '5', 'kind' => 'restock', 'at' => '2026-10-17T12:00:00.000000Z',
            'actor' => ['id' => $world['dana']->id, 'name' => 'Dana'], 'amount' => 50, 'note' => null,
        ]);
});

it('broadcasts a correction with its version, the new remaining and a correction entry', function () {
    freezeClock('2026-10-17 12:00:00 UTC');
    Event::fake([PoolUpdated::class]);
    $world = workedPool();

    correct($world, 2, 'amend', 20, 'Counted wrong');

    Event::assertDispatchedTimes(PoolUpdated::class, 1);
    Event::assertDispatched(PoolUpdated::class, fn (PoolUpdated $event) => $event->version === 5
        && $event->remaining === 20
        && $event->entry === [
            'seq' => 5, 'cursor' => '5', 'kind' => 'correction', 'at' => '2026-10-17T12:00:00.000000Z',
            'supervisor' => ['id' => $world['dana']->id, 'name' => 'Dana'],
            'operator' => ['id' => $world['ana']->id, 'name' => 'Ana'],
            'allocation_seq' => 2, 'correction_kind' => 'amend', 'change' => ['from' => 40, 'to' => 20], 'reason' => 'Counted wrong',
        ]);
});

it('does not broadcast a replayed or refused restock or correction', function () {
    freezeClock('2026-10-17 12:00:00 UTC');
    $world = workedPool();
    $service = app(RestockService::class);
    $service->add($world['pool'], $world['dana'], new AddRestock(5, null, 'r-1'));
    correct($world, 2, 'amend', 39, key: 'c-1');

    Event::fake([PoolUpdated::class]);

    $service->add($world['pool'], $world['dana'], new AddRestock(5, null, 'r-1'));
    correct($world, 2, 'amend', 39, key: 'c-1');
    expect(fn () => correct($world, 3, 'amend', 500))->toThrow(CorrectionRefused::class);
    expect(fn () => correct($world, 3, 'amend', 50))->toThrow(ValidationException::class);

    Event::assertNotDispatched(PoolUpdated::class);
});

it('broadcasts a restock and a correction only after the transaction has closed', function () {
    freezeClock('2026-10-17 12:00:00 UTC');
    $world = workedPool();
    $levels = [];
    $baseline = DB::transactionLevel();
    Event::listen(PoolUpdated::class, function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });

    restockUnits($world, 5);
    correct($world, 2, 'amend', 30);

    expect($levels)->toBe([$baseline, $baseline]);
});

it('still stores a restock and a correction when the broadcaster throws, and reports it', function () {
    Exceptions::fake();
    Broadcast::extend('throwing', fn () => new class extends Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new RuntimeException('reverb is down');
        }
    });
    config(['broadcasting.default' => 'throwing', 'broadcasting.connections.throwing' => ['driver' => 'throwing']]);
    freezeClock('2026-10-17 12:00:00 UTC');
    $world = workedPool();

    expect(restockUnits($world, 5)->pool->remaining)->toBe(5)
        ->and(correct($world, 2, 'amend', 30)->pool->remaining)->toBe(15);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'reverb is down');
});
