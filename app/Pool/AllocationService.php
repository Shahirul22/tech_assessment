<?php

namespace App\Pool;

use App\Events\PoolUpdated;
use App\Http\Resources\LogEntryResource;
use App\Models\Allocation;
use App\Models\Pool;
use App\Models\User;
use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Config\RulesConfig;
use App\Pool\Exceptions\IdempotencyKeyConflict;
use App\Pool\Exceptions\PoolBusy;
use App\Pool\Log\LogEntry;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * The only write path for allocations (DD1-DD3, DD12, DD13).
 *
 * Every write to a pool takes that pool's row lock first. Under the lock it reloads the ledger, recomputes what
 * remains, grants min(requested, remaining) and stores the result. Commit order is therefore the order requests
 * are served in, and each stored entry takes the next number of the pool's sequence (its version).
 */
class AllocationService
{
    /** Postgres SQLSTATE lock_not_available, raised when lock_timeout expires. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private readonly Clock $clock,
        private readonly LedgerRepository $ledgers,
        private readonly PoolCalculator $calculator,
        private readonly LedgerSequence $sequence,
        private readonly RulesConfig $rules,
    ) {}

    /**
     * @throws IdempotencyKeyConflict when the key was used before for a different operator, amount or type
     * @throws PoolBusy when the pool lock could not be taken within 5 seconds
     */
    public function submit(Pool $pool, User $operator, SubmitAllocation $command): AllocationResult
    {
        // Receipt time is read first, before any waiting for the lock (DD13).
        $receipt = $this->clock->now();

        try {
            $result = DB::transaction(fn () => $this->submitUnderLock($pool, $operator, $command, $receipt));
        } catch (QueryException $e) {
            throw $this->isLockTimeout($e) ? new PoolBusy($e) : $e;
        }

        // The transaction has committed and the lock is released. A replay stored nothing new, so it announces nothing.
        if (! $result->replayed) {
            $this->announce($result);
        }

        return $result;
    }

    /**
     * Tell every open screen about the new entry (DD7, FR11).
     *
     * It runs after the response is sent, so it never delays the request or holds the lock. A broadcaster that is down
     * is reported and swallowed: the allocation is already stored and the screens catch up by polling.
     */
    private function announce(AllocationResult $result): void
    {
        $event = new PoolUpdated(
            poolId: $result->allocation->pool_id,
            version: $result->pool->version,
            remaining: $result->pool->remaining,
            entry: LogEntryResource::make(LogEntry::fromAllocation($result->allocation, $this->expiresAt($result->allocation)))->resolve(),
        );

        defer(function () use ($event) {
            rescue(function () use ($event) {
                // A statement of its own: the pending broadcast sends when it goes out of scope, inside rescue().
                broadcast($event);
            }, report: true);
        });
    }

    private function submitUnderLock(Pool $pool, User $operator, SubmitAllocation $command, CarbonImmutable $receipt): AllocationResult
    {
        // Isolation stays at Postgres's default, READ COMMITTED. After the lock is granted every statement sees all
        // committed rows, so the ledger read below is exact. REPEATABLE READ would read a snapshot taken before the
        // wait for the lock and could miss a request committed in the meantime.
        DB::statement("SET LOCAL lock_timeout = '5s'");

        $locked = Pool::query()->whereKey($pool->getKey())->lockForUpdate()->firstOrFail();

        $existing = $locked->allocations()->where('idempotency_key', $command->idempotencyKey)->first();
        $ledger = $this->ledgers->load($locked);

        if ($existing !== null) {
            return $this->replay($existing, $operator, $command, $ledger);
        }

        $instant = $this->evaluationInstant($ledger, $receipt);
        $granted = $this->calculator->grantFor($this->calculator->calculateAt($ledger, $instant), $command->requested);
        // The entry's number comes from the one shared sequence, which also stores it as the pool's version.
        $seq = $this->sequence->next($locked);

        $allocation = Allocation::create([
            'pool_id' => $locked->id,
            'seq' => $seq,
            'operator_id' => $operator->id,
            'type' => $command->type,
            'requested' => $command->requested,
            'granted' => $granted,
            'tier_at_log' => $operator->tier,
            'effective_at' => $receipt,
            'recorded_at' => $this->clock->now(),
            'idempotency_key' => $command->idempotencyKey,
        ]);
        $allocation->setRelation('operator', $operator);

        // The pool as it stands with the new entry in the ledger. No second read: the transaction stays short.
        $after = $this->calculator->calculateAt(new Ledger(
            $ledger->restocks,
            [...$ledger->allocations, $this->entryFor($allocation)],
            $ledger->corrections,
        ), $instant);

        return new AllocationResult($allocation, $after, replayed: false);
    }

    /**
     * The same key with the same request returns the stored result, with the pool as it stood right after that
     * allocation, so every replay carries the original result. A different request under the key is refused.
     */
    private function replay(Allocation $existing, User $operator, SubmitAllocation $command, Ledger $ledger): AllocationResult
    {
        $samePayload = $existing->operator_id === $operator->id
            && $existing->requested === $command->requested
            && $existing->type === $command->type;

        if (! $samePayload) {
            throw new IdempotencyKeyConflict($command->idempotencyKey);
        }

        $asOfThen = new Ledger(
            array_values(array_filter($ledger->restocks, fn ($entry) => $entry->seq <= $existing->seq)),
            array_values(array_filter($ledger->allocations, fn ($entry) => $entry->seq <= $existing->seq)),
            array_values(array_filter($ledger->corrections, fn ($entry) => $entry->seq <= $existing->seq)),
        );

        $existing->setRelation('operator', $operator);

        // The as-of ledger includes the replayed entry, so this is the instant the original request was served at.
        $instant = $this->latestEffectiveAt($asOfThen) ?? $existing->effective_at;

        return new AllocationResult($existing, $this->calculator->calculateAt($asOfThen, $instant), replayed: true);
    }

    /**
     * The instant a write is evaluated at: its receipt time, but never earlier than the newest allocation already in
     * the ledger. A request received earlier can commit after one received later (the lock decides the order), and
     * evaluating it at its own earlier receipt time would let a later entry's expiry appear to come back.
     * Evaluating at a non-decreasing instant keeps each reply consistent with the ledger it was served from, and
     * makes a replay reproduce the original (FR5.8, FR5.9). The stored effective_at stays the receipt time.
     */
    private function evaluationInstant(Ledger $ledger, CarbonImmutable $receipt): CarbonImmutable
    {
        $latest = $this->latestEffectiveAt($ledger);

        return $latest !== null && $latest->greaterThan($receipt) ? $latest : $receipt;
    }

    private function latestEffectiveAt(Ledger $ledger): ?CarbonImmutable
    {
        $latest = null;

        foreach ($ledger->allocations as $entry) {
            if ($latest === null || $entry->effectiveAt->greaterThan($latest)) {
                $latest = $entry->effectiveAt;
            }
        }

        return $latest;
    }

    /**
     * Null for consumed and zero grants, which hold nothing that can return.
     */
    private function expiresAt(Allocation $allocation): ?CarbonImmutable
    {
        return $allocation->type === 'temporary' && $allocation->granted > 0
            ? $this->rules->expiresAt($allocation->tier_at_log, $allocation->effective_at)
            : null;
    }

    private function entryFor(Allocation $allocation): AllocationEntry
    {
        return new AllocationEntry(
            id: $allocation->id,
            seq: $allocation->seq,
            operatorId: $allocation->operator_id,
            type: $allocation->type,
            units: $allocation->granted,
            tierAtLog: $allocation->tier_at_log,
            effectiveAt: $allocation->effective_at,
        );
    }

    private function isLockTimeout(QueryException $e): bool
    {
        return (string) $e->getPrevious()?->getCode() === self::LOCK_NOT_AVAILABLE;
    }
}
