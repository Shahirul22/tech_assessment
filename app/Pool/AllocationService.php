<?php

namespace App\Pool;

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

/**
 * The only write path for allocations (DD1-DD3, DD12, DD13).
 *
 * Every write to a pool takes that pool's row lock first. Under the lock it reloads the ledger, recomputes what
 * remains, grants min(requested, remaining) and stores the result. Commit order is therefore the order requests
 * are served in, and each stored entry takes the next number of the pool's sequence (its version).
 */
class AllocationService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly LedgerRepository $ledgers,
        private readonly PoolCalculator $calculator,
        private readonly LedgerSequence $sequence,
        private readonly RulesConfig $rules,
        private readonly PoolWriteGate $gate,
    ) {}

    /**
     * @throws IdempotencyKeyConflict when the key was used before for a different operator, amount or type
     * @throws PoolBusy when the pool lock could not be taken within 5 seconds
     */
    public function submit(Pool $pool, User $operator, SubmitAllocation $command): AllocationResult
    {
        // Receipt time is read first, before any waiting for the lock (DD13).
        $receipt = $this->clock->now();

        $result = $this->gate->locked($pool, fn (Pool $locked) => $this->submitUnderLock($locked, $operator, $command, $receipt));

        // The transaction has committed and the lock is released. A replay stored nothing new, so it announces nothing.
        if (! $result->replayed) {
            $this->gate->announce(
                $result->allocation->pool_id,
                $result->pool->version,
                $result->pool->remaining,
                LogEntry::fromAllocation($result->allocation, $this->expiresAt($result->allocation)),
            );
        }

        return $result;
    }

    private function submitUnderLock(Pool $locked, User $operator, SubmitAllocation $command, CarbonImmutable $receipt): AllocationResult
    {
        $existing = $locked->allocations()->where('idempotency_key', $command->idempotencyKey)->first();
        $ledger = $this->ledgers->load($locked);

        if ($existing !== null) {
            return $this->replay($existing, $operator, $command, $ledger);
        }

        $instant = $ledger->evaluationInstant($receipt);
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

        $asOfThen = $ledger->asOf($existing->seq);

        $existing->setRelation('operator', $operator);

        // The as-of ledger includes the replayed entry, so this is the instant the original request was served at.
        $instant = $asOfThen->instantAt($existing->seq) ?? $existing->effective_at;

        return new AllocationResult($existing, $this->calculator->calculateAt($asOfThen, $instant), replayed: true);
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
}
