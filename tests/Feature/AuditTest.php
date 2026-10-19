<?php

use App\Models\Allocation;
use App\Models\Pool;
use App\Models\User;
use App\Pool\AddRestock;
use App\Pool\RestockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function auditAs(?User $user, $pool)
{
    return test()->getJson("/api/pools/{$pool->id}/audit", $user === null ? [] : ['X-Demo-User' => (string) $user->id]);
}

it('lists a correction with the original, the corrected value, the reason and Ana\'s status before and after', function () {
    freezeClock('2026-10-17 14:00:00 UTC');
    $world = workedPool();
    correct($world, 2, 'amend', 20, 'Counted wrong');

    $audit = auditAs($world['dana'], $world['pool'])->assertOk();

    expect($audit->json('version'))->toBe(5)
        ->and($audit->json('server_time'))->toBe('2026-10-17T14:00:00.000000Z')
        ->and($audit->json('corrections'))->toHaveCount(1)
        ->and($audit->json('corrections.0'))->toMatchArray([
            'seq' => 5, 'allocation_seq' => 2, 'kind' => 'amend', 'from' => 40, 'to' => 20, 'reason' => 'Counted wrong',
            'at' => '2026-10-17T14:00:00.000000Z',
            'operator' => ['id' => $world['ana']->id, 'name' => 'Ana'],
            'supervisor' => ['id' => $world['dana']->id, 'name' => 'Dana'],
            'original' => ['requested' => 40, 'granted' => 40],
        ])
        ->and($audit->json('corrections.0.status.before'))->toBe('high')
        ->and($audit->json('corrections.0.status.after'))->toBe('elevated')
        ->and($audit->json('hindsight'))->toBe([]);
});

it('keeps the original allocation retrievable and never changes it', function () {
    freezeClock('2026-10-17 14:00:00 UTC');
    $world = workedPool();
    correct($world, 2, 'amend', 20);

    auditAs($world['dana'], $world['pool'])->assertOk();

    expect($world['allocations']['ana']->fresh()->granted)->toBe(40);
});

it('lists only the later status points where before and after differ', function () {
    freezeClock('2026-10-17 14:00:00 UTC');
    $pool = Pool::factory()->create(['version' => 3]);
    DB::table('restocks')->insert(['pool_id' => $pool->id, 'seq' => 1, 'amount' => 100, 'recorded_at' => '2026-10-17 08:00:00+00']);
    $ana = operatorNamed('Ana');
    $dana = User::factory()->supervisor()->create(['name' => 'Dana']);
    foreach ([[2, 28, '09:00:00'], [3, 5, '10:30:00']] as [$seq, $units, $at]) {
        Allocation::factory()->create(['pool_id' => $pool->id, 'seq' => $seq, 'operator_id' => $ana->id, 'requested' => $units, 'granted' => $units,
            'effective_at' => "2026-10-17 {$at}+00", 'recorded_at' => "2026-10-17 {$at}+00"]);
    }
    $world = ['pool' => $pool, 'dana' => $dana];

    correct($world, 2, 'amend', 20);

    $status = auditAs($dana, $pool)->assertOk()->json('corrections.0.status');

    // 33 (high) becomes 25 (elevated). Right after the first request (28 or 20) both are elevated, so it is left out.
    expect($status['before'])->toBe('high')
        ->and($status['after'])->toBe('elevated')
        ->and(array_column($status['points'], 'label'))->toBe(['when corrected', "after Ana's request #3", 'now'])
        ->and($status['points'][1])->toMatchArray(['seq' => 3, 'at' => '2026-10-17T10:30:00.000000Z', 'before' => 'high', 'after' => 'elevated']);
});

it('never shows Ben or Cara in the status block of a correction of Ana', function () {
    freezeClock('2026-10-17 14:00:00 UTC');
    $world = workedPool();
    correct($world, 2, 'amend', 20);

    $json = json_encode(auditAs($world['dana'], $world['pool'])->json('corrections.0.status'));

    expect($json)->not->toContain('Ben')->not->toContain('Cara');
});

it('flags the stretch that was overdrawn in hindsight and still accepts the correction', function () {
    $clock = freezeClock('2026-10-17 13:00:00 UTC');
    $world = workedPool();
    app(RestockService::class)->add($world['pool'], $world['dana'], new AddRestock(50, 'Delivery', (string) Str::uuid()));

    $clock->set(CarbonImmutable::parse('2026-10-17 14:00:00 UTC'));
    $accepted = correct($world, 2, 'amend', 60);

    $audit = auditAs($world['dana'], $world['pool'])->assertOk();

    expect($accepted->pool->remaining)->toBe(30)
        ->and($audit->json('hindsight'))->toBe([[
            'from' => ['seq' => 3, 'at' => '2026-10-17T10:00:00.000000Z'],
            'to' => ['seq' => 5, 'at' => '2026-10-17T13:00:00.000000Z'],
            'lowest' => -20,
            'corrections' => [6],
        ]]);

    $this->getJson("/api/pools/{$world['pool']->id}", ['X-Demo-User' => (string) $world['ana']->id])->assertJsonPath('remaining', 30);
});

it('lists several corrections oldest first, in commit order', function () {
    freezeClock('2026-10-17 14:00:00 UTC');
    $world = workedPool();
    correct($world, 3, 'amend', 45);
    correct($world, 2, 'amend', 20);
    correct($world, 3, 'void');

    $corrections = auditAs($world['dana'], $world['pool'])->assertOk()->json('corrections');

    expect(array_column($corrections, 'seq'))->toBe([5, 6, 7])
        ->and(array_column($corrections, 'allocation_seq'))->toBe([3, 2, 3])
        ->and(array_column($corrections, 'from'))->toBe([50, 40, 45]);
});

it('shows an empty history as empty lists', function () {
    $world = workedPool();

    auditAs($world['dana'], $world['pool'])->assertOk()->assertJsonPath('corrections', [])->assertJsonPath('hindsight', []);
});

it('refuses an operator, an unknown user and an unknown pool', function () {
    $world = workedPool();

    auditAs($world['ana'], $world['pool'])->assertForbidden()->assertJsonPath('error.code', 'forbidden_role');
    auditAs(null, $world['pool'])->assertStatus(401);
    $this->getJson('/api/pools/999999/audit', ['X-Demo-User' => (string) $world['dana']->id])->assertNotFound();
});
