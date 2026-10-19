<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const TS = 'timestamp(6) with time zone';

/**
 * Columns each plan §5 table must have: name => [type, nullable].
 */
function expectedColumns(): array
{
    return [
        'users' => [
            'id' => ['bigint', false], 'name' => ['text', false], 'role' => ['text', false], 'tier' => ['text', true],
            'created_at' => [TS, true], 'updated_at' => [TS, true],
        ],
        'pools' => [
            'id' => ['bigint', false], 'name' => ['text', false], 'version' => ['bigint', false],
            'created_at' => [TS, true], 'updated_at' => [TS, true],
        ],
        'restocks' => [
            'id' => ['bigint', false], 'pool_id' => ['bigint', false], 'seq' => ['bigint', false],
            'amount' => ['integer', false], 'actor_id' => ['bigint', true], 'note' => ['text', true],
            'recorded_at' => [TS, false],
        ],
        'allocations' => [
            'id' => ['bigint', false], 'pool_id' => ['bigint', false], 'seq' => ['bigint', false],
            'operator_id' => ['bigint', false], 'type' => ['text', false], 'requested' => ['integer', false],
            'granted' => ['integer', false], 'tier_at_log' => ['text', false], 'effective_at' => [TS, false],
            'recorded_at' => [TS, false], 'idempotency_key' => ['text', false],
        ],
        'corrections' => [
            'id' => ['bigint', false], 'pool_id' => ['bigint', false], 'seq' => ['bigint', false],
            'allocation_id' => ['bigint', false], 'supervisor_id' => ['bigint', false], 'kind' => ['text', false],
            'new_amount' => ['integer', true], 'reason' => ['text', false], 'effective_at' => [TS, false],
            'recorded_at' => [TS, false], 'idempotency_key' => ['text', false],
        ],
    ];
}

/**
 * Run an insert that the database must refuse because of the named constraint.
 * The nested transaction is a savepoint, so the failed statement does not poison the test's transaction.
 */
function expectRejected(string $constraint, Closure $insert): void
{
    try {
        DB::transaction($insert);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($constraint);

        return;
    }

    test()->fail("Expected the database to reject the row because of [{$constraint}].");
}

function ts(): string
{
    return now()->format('Y-m-d H:i:s.uP');
}

function newUser(array $overrides = []): int
{
    return DB::table('users')->insertGetId($overrides + ['name' => 'Tess', 'role' => 'operator', 'tier' => 'bronze']);
}

function newPool(): int
{
    return DB::table('pools')->insertGetId(['name' => 'P']);
}

function allocationRow(int $poolId, int $operatorId, array $overrides = []): array
{
    return $overrides + [
        'pool_id' => $poolId, 'seq' => 1, 'operator_id' => $operatorId, 'type' => 'consumed', 'requested' => 3,
        'granted' => 3, 'tier_at_log' => 'bronze', 'effective_at' => ts(), 'recorded_at' => ts(),
        'idempotency_key' => 'key-1',
    ];
}

function correctionRow(int $poolId, int $allocationId, int $supervisorId, array $overrides = []): array
{
    return $overrides + [
        'pool_id' => $poolId, 'seq' => 2, 'allocation_id' => $allocationId, 'supervisor_id' => $supervisorId,
        'kind' => 'amend', 'new_amount' => 2, 'reason' => 'typo', 'effective_at' => ts(), 'recorded_at' => ts(),
        'idempotency_key' => 'corr-1',
    ];
}

it('has exactly the plan §5 columns and types on every table', function (string $table) {
    $actual = collect(Schema::getColumns($table))
        ->mapWithKeys(fn (array $c) => [$c['name'] => [$c['type'], $c['nullable']]])
        ->all();

    expect($actual)->toEqual(expectedColumns()[$table]);
})->with(['users', 'pools', 'restocks', 'allocations', 'corrections']);

it('defaults pools.version to 0 and allocations.type to consumed', function () {
    $poolId = newPool();
    $operatorId = newUser();
    $row = allocationRow($poolId, $operatorId);
    unset($row['type']);
    $allocationId = DB::table('allocations')->insertGetId($row);

    expect(DB::table('pools')->where('id', $poolId)->value('version'))->toBe(0);

    $default = collect(Schema::getColumns('allocations'))->firstWhere('name', 'type')['default'];
    expect($default)->toContain('consumed')
        ->and(DB::table('allocations')->where('id', $allocationId)->value('type'))->toBe('consumed');
});

it('has the unique constraints the plan requires', function (string $table, array $columns) {
    $unique = collect(Schema::getIndexes($table))->contains(
        fn (array $i) => $i['unique'] && $i['columns'] === $columns,
    );

    expect($unique)->toBeTrue("{$table} must be unique on (".implode(', ', $columns).')');
})->with([
    'restocks seq' => ['restocks', ['pool_id', 'seq']],
    'allocations seq' => ['allocations', ['pool_id', 'seq']],
    'corrections seq' => ['corrections', ['pool_id', 'seq']],
    'allocations idempotency' => ['allocations', ['pool_id', 'idempotency_key']],
    'corrections idempotency' => ['corrections', ['pool_id', 'idempotency_key']],
]);

it('accepts valid rows, including the boundary values', function () {
    $poolId = newPool();
    $operatorId = newUser();
    $supervisorId = newUser(['role' => 'supervisor', 'tier' => null]);

    DB::table('restocks')->insert(['pool_id' => $poolId, 'seq' => 1, 'amount' => 1, 'recorded_at' => ts()]);
    DB::table('allocations')->insert(allocationRow($poolId, $operatorId, ['seq' => 2, 'idempotency_key' => 'a', 'requested' => 3, 'granted' => 0]));
    DB::table('allocations')->insert(allocationRow($poolId, $operatorId, ['seq' => 3, 'idempotency_key' => 'b', 'requested' => 3, 'granted' => 3, 'type' => 'temporary']));
    $allocationId = DB::table('allocations')->where('seq', 3)->value('id');
    DB::table('corrections')->insert(correctionRow($poolId, $allocationId, $supervisorId, ['seq' => 4, 'idempotency_key' => 'c1', 'kind' => 'amend', 'new_amount' => 1]));
    DB::table('corrections')->insert(correctionRow($poolId, $allocationId, $supervisorId, ['seq' => 5, 'idempotency_key' => 'c2', 'kind' => 'void', 'new_amount' => null]));

    expect(DB::table('allocations')->count())->toBe(2)
        ->and(DB::table('corrections')->count())->toBe(2);
});

it('rejects a user whose role and tier do not match', function (array $user, string $constraint) {
    expectRejected($constraint, fn () => newUser($user));
})->with([
    'operator without a tier' => [['role' => 'operator', 'tier' => null], 'users_role_tier_check'],
    'supervisor with a tier' => [['role' => 'supervisor', 'tier' => 'gold'], 'users_role_tier_check'],
    'unknown role' => [['role' => 'admin', 'tier' => null], 'users_role_check'],
]);

it('rejects a restock that adds nothing', function (int $amount) {
    $poolId = newPool();

    expectRejected('restocks_amount_check', fn () => DB::table('restocks')->insert(
        ['pool_id' => $poolId, 'seq' => 1, 'amount' => $amount, 'recorded_at' => ts()],
    ));
})->with([0, -5]);

it('rejects an allocation the ledger could never hold', function (array $overrides, string $constraint) {
    $poolId = newPool();
    $operatorId = newUser();

    expectRejected($constraint, fn () => DB::table('allocations')->insert(allocationRow($poolId, $operatorId, $overrides)));
})->with([
    'granted below zero' => [['granted' => -1], 'allocations_granted_check'],
    'granted above requested' => [['requested' => 3, 'granted' => 4], 'allocations_granted_check'],
    'requested zero' => [['requested' => 0, 'granted' => 0], 'allocations_requested_check'],
    // Any negative requested also breaks granted <= requested, and Postgres reports that constraint first.
    'requested negative' => [['requested' => -2, 'granted' => 0], 'allocations_granted_check'],
    'unknown type' => [['type' => 'borrowed'], 'allocations_type_check'],
]);

it('rejects a correction whose kind and new_amount disagree', function (array $overrides, string $constraint) {
    $poolId = newPool();
    $operatorId = newUser();
    $supervisorId = newUser(['role' => 'supervisor', 'tier' => null]);
    $allocationId = DB::table('allocations')->insertGetId(allocationRow($poolId, $operatorId));

    expectRejected($constraint, fn () => DB::table('corrections')->insert(correctionRow($poolId, $allocationId, $supervisorId, $overrides)));
})->with([
    'void with an amount' => [['kind' => 'void', 'new_amount' => 3], 'corrections_kind_new_amount_check'],
    'amend without an amount' => [['kind' => 'amend', 'new_amount' => null], 'corrections_kind_new_amount_check'],
    'amend to zero' => [['kind' => 'amend', 'new_amount' => 0], 'corrections_kind_new_amount_check'],
    'unknown kind' => [['kind' => 'delete', 'new_amount' => null], 'corrections_kind_check'],
]);

it('rejects a second ledger row with the same seq in one pool', function () {
    $poolId = newPool();
    $operatorId = newUser();
    $supervisorId = newUser(['role' => 'supervisor', 'tier' => null]);
    $allocationId = DB::table('allocations')->insertGetId(allocationRow($poolId, $operatorId));
    DB::table('restocks')->insert(['pool_id' => $poolId, 'seq' => 2, 'amount' => 5, 'recorded_at' => ts()]);
    DB::table('corrections')->insert(correctionRow($poolId, $allocationId, $supervisorId, ['seq' => 3]));

    expectRejected('allocations_pool_id_seq_unique', fn () => DB::table('allocations')->insert(allocationRow($poolId, $operatorId, ['idempotency_key' => 'other'])));
    expectRejected('restocks_pool_id_seq_unique', fn () => DB::table('restocks')->insert(['pool_id' => $poolId, 'seq' => 2, 'amount' => 1, 'recorded_at' => ts()]));
    expectRejected('corrections_pool_id_seq_unique', fn () => DB::table('corrections')->insert(correctionRow($poolId, $allocationId, $supervisorId, ['seq' => 3, 'idempotency_key' => 'other'])));
});

it('rejects a second allocation or correction with the same idempotency key in one pool, but not in another', function () {
    $poolId = newPool();
    $otherPoolId = newPool();
    $operatorId = newUser();
    $supervisorId = newUser(['role' => 'supervisor', 'tier' => null]);
    $allocationId = DB::table('allocations')->insertGetId(allocationRow($poolId, $operatorId));
    DB::table('corrections')->insert(correctionRow($poolId, $allocationId, $supervisorId));

    expectRejected('allocations_pool_id_idempotency_key_unique', fn () => DB::table('allocations')->insert(allocationRow($poolId, $operatorId, ['seq' => 9])));
    expectRejected('corrections_pool_id_idempotency_key_unique', fn () => DB::table('corrections')->insert(correctionRow($poolId, $allocationId, $supervisorId, ['seq' => 9])));

    DB::table('allocations')->insert(allocationRow($otherPoolId, $operatorId));
    expect(DB::table('allocations')->where('idempotency_key', 'key-1')->count())->toBe(2);
});

it('enforces the foreign keys', function () {
    $poolId = newPool();
    $operatorId = newUser();

    expectRejected('restocks_pool_id_foreign', fn () => DB::table('restocks')->insert(['pool_id' => 999999, 'seq' => 1, 'amount' => 1, 'recorded_at' => ts()]));
    expectRejected('restocks_actor_id_foreign', fn () => DB::table('restocks')->insert(['pool_id' => $poolId, 'seq' => 1, 'amount' => 1, 'actor_id' => 999999, 'recorded_at' => ts()]));
    expectRejected('allocations_operator_id_foreign', fn () => DB::table('allocations')->insert(allocationRow($poolId, 999999)));
    expectRejected('corrections_allocation_id_foreign', fn () => DB::table('corrections')->insert(correctionRow($poolId, 999999, $operatorId)));
});
