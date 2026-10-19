<?php

use App\Http\Resources\LogEntryResource;
use App\Models\Allocation;
use App\Models\Correction;
use App\Models\Pool;
use App\Models\Restock;
use App\Models\User;
use App\Pool\Log\LogEntry;
use Carbon\CarbonImmutable;

function rendered(LogEntry $entry): array
{
    return (new LogEntryResource($entry))->resolve();
}

function allocationEntry(int $requested, int $granted, string $type = 'consumed'): LogEntry
{
    $ana = User::factory()->operator()->create(['name' => 'Ana']);
    $allocation = Allocation::factory()->for(Pool::factory())->create([
        'seq' => 12, 'operator_id' => $ana->id, 'type' => $type, 'requested' => $requested, 'granted' => $granted,
        'effective_at' => CarbonImmutable::parse('2026-10-17 10:00:00.123456 UTC'),
    ]);

    return LogEntry::fromAllocation($allocation->load('operator'));
}

it('renders an allocation with its outcome', function (int $requested, int $granted, string $outcome) {
    $entry = rendered(allocationEntry($requested, $granted));

    expect($entry)->toBe([
        'seq' => 12,
        'cursor' => '12',
        'kind' => 'allocation',
        'at' => '2026-10-17T10:00:00.123456Z',
        'operator' => ['id' => $entry['operator']['id'], 'name' => 'Ana'],
        'type' => 'consumed',
        'requested' => $requested,
        'granted' => $granted,
        'outcome' => $outcome,
        'expires_at' => null,
    ]);
})->with([
    'full' => [3, 3, 'full'],
    'partial' => [6, 4, 'partial'],
    'none' => [3, 0, 'none'],
]);

it('renders a restock, with no actor for the seeded stock', function () {
    $pool = Pool::factory()->create();
    $dana = User::factory()->supervisor()->create(['name' => 'Dana']);
    $at = CarbonImmutable::parse('2026-10-17 09:00:00 UTC');

    $seed = Restock::factory()->create(['pool_id' => $pool->id, 'seq' => 1, 'amount' => 100, 'actor_id' => null, 'note' => 'Initial stock', 'recorded_at' => $at]);
    $added = Restock::factory()->create(['pool_id' => $pool->id, 'seq' => 9, 'amount' => 25, 'actor_id' => $dana->id, 'note' => null, 'recorded_at' => $at]);

    expect(rendered(LogEntry::fromRestock($seed->load('actor'))))->toBe([
        'seq' => 1, 'cursor' => '1', 'kind' => 'restock', 'at' => '2026-10-17T09:00:00.000000Z', 'actor' => null, 'amount' => 100, 'note' => 'Initial stock',
    ])->and(rendered(LogEntry::fromRestock($added->load('actor'))))->toBe([
        'seq' => 9, 'cursor' => '9', 'kind' => 'restock', 'at' => '2026-10-17T09:00:00.000000Z', 'actor' => ['id' => $dana->id, 'name' => 'Dana'], 'amount' => 25, 'note' => null,
    ]);
});

it('renders a correction as a change from one amount to another, or to nothing for a void', function () {
    $pool = Pool::factory()->create();
    $dana = User::factory()->supervisor()->create(['name' => 'Dana']);
    $allocation = Allocation::factory()->create(['pool_id' => $pool->id, 'seq' => 12, 'requested' => 3, 'granted' => 3]);
    $at = CarbonImmutable::parse('2026-10-17 11:00:00 UTC');
    $row = ['pool_id' => $pool->id, 'allocation_id' => $allocation->id, 'supervisor_id' => $dana->id, 'effective_at' => $at, 'recorded_at' => $at];

    $amend = Correction::create($row + ['seq' => 20, 'kind' => 'amend', 'new_amount' => 2, 'reason' => 'miscounted', 'idempotency_key' => 'a']);
    $void = Correction::create($row + ['seq' => 21, 'kind' => 'void', 'new_amount' => null, 'reason' => 'duplicate', 'idempotency_key' => 'b']);

    expect(rendered(LogEntry::fromCorrection($amend->load(['supervisor', 'allocation']))))->toBe([
        'seq' => 20, 'cursor' => '20', 'kind' => 'correction', 'at' => '2026-10-17T11:00:00.000000Z',
        'supervisor' => ['id' => $dana->id, 'name' => 'Dana'], 'allocation_seq' => 12,
        'change' => ['from' => 3, 'to' => 2], 'reason' => 'miscounted',
    ])->and(rendered(LogEntry::fromCorrection($void->load(['supervisor', 'allocation'])))['change'])->toBe(['from' => 3, 'to' => null]);
});

it('renders a derived expiry with no seq, its operator and a cursor made of its anchor', function () {
    $entry = LogEntry::expiry(
        CarbonImmutable::parse('2026-10-18 10:00:00.123456 UTC'),
        allocationSeq: 12,
        units: 3,
        operator: ['id' => 7, 'name' => 'Ana'],
        anchor: 15,
    );

    expect(rendered($entry))->toBe([
        'seq' => null, 'cursor' => '15x12', 'kind' => 'expiry', 'at' => '2026-10-18T10:00:00.123456Z',
        'operator' => ['id' => 7, 'name' => 'Ana'], 'allocation_seq' => 12, 'units' => 3,
    ]);
});

it('renders expires_at on a temporary allocation entry', function () {
    $expiresAt = CarbonImmutable::parse('2026-10-18 10:00:00.5 UTC');
    $ana = User::factory()->operator()->create(['name' => 'Ana']);
    $allocation = Allocation::factory()->for(Pool::factory())->create([
        'seq' => 12, 'operator_id' => $ana->id, 'type' => 'temporary', 'requested' => 3, 'granted' => 3,
    ]);

    expect(rendered(LogEntry::fromAllocation($allocation->load('operator'), $expiresAt))['expires_at'])->toBe('2026-10-18T10:00:00.500000Z');
});

it('renders an entry on its own, without a data wrapper', function () {
    $json = (new LogEntryResource(LogEntry::expiry(CarbonImmutable::parse('2026-10-18 10:00:00 UTC'), 12, 3, ['id' => 7, 'name' => 'Ana'], 15)))
        ->toResponse(request())->getData(true);

    expect($json)->toHaveKey('kind')->not->toHaveKey('data');
});
