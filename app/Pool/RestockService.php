<?php

namespace App\Pool;

use App\Models\Pool;
use App\Models\Restock;
use App\Models\User;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Calculation\RestockEntry;
use App\Pool\Exceptions\IdempotencyKeyConflict;
use App\Pool\Exceptions\PoolBusy;
use App\Pool\Log\LogEntry;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * The only write path for restocks (M3 DD1, DD6, FR10).
 *
 * Like an allocation, a restock takes the pool's row lock, reloads the ledger, takes the next number of the shared
 * sequence and stores one row. Restocks only add capacity: there is no way to lower it (FR10.2).
 */
class RestockService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly LedgerRepository $ledgers,
        private readonly PoolCalculator $calculator,
        private readonly LedgerSequence $sequence,
        private readonly PoolWriteGate $gate,
    ) {}

    /**
     * @throws IdempotencyKeyConflict when the key was used before for a different supervisor, amount or note
     * @throws PoolBusy when the pool lock could not be taken within 5 seconds
     */
    public function add(Pool $pool, User $supervisor, AddRestock $command): RestockResult
    {
        // Receipt time is read first, before any waiting for the lock (M1 DD13).
        $receipt = $this->clock->now();

        $result = $this->gate->locked($pool, fn (Pool $locked) => $this->addUnderLock($locked, $supervisor, $command, $receipt));

        // The transaction has committed and the lock is released. A replay stored nothing new, so it announces nothing.
        if (! $result->replayed) {
            $this->gate->announce($result->restock->pool_id, $result->pool->version, $result->pool->remaining, LogEntry::fromRestock($result->restock));
        }

        return $result;
    }

    private function addUnderLock(Pool $locked, User $supervisor, AddRestock $command, CarbonImmutable $receipt): RestockResult
    {
        $existing = $locked->restocks()->where('idempotency_key', $command->idempotencyKey)->first();
        $ledger = $this->ledgers->load($locked);

        if ($existing !== null) {
            return $this->replay($existing, $supervisor, $command, $ledger);
        }

        $instant = $ledger->evaluationInstant($receipt);
        $seq = $this->sequence->next($locked);

        $restock = Restock::create([
            'pool_id' => $locked->id,
            'seq' => $seq,
            'amount' => $command->amount,
            'actor_id' => $supervisor->id,
            'note' => $command->note,
            'recorded_at' => $receipt,
            'idempotency_key' => $command->idempotencyKey,
        ]);
        $restock->setRelation('actor', $supervisor);

        $after = $this->calculator->calculateAt(new Ledger(
            [...$ledger->restocks, new RestockEntry($restock->id, $seq, $restock->amount, $receipt)],
            $ledger->allocations,
            $ledger->corrections,
        ), $instant);

        return new RestockResult($restock, $after, replayed: false);
    }

    /**
     * The same key with the same request returns the stored result, with the pool as it stood right after that restock.
     * A different request under the key is refused.
     */
    private function replay(Restock $existing, User $supervisor, AddRestock $command, Ledger $ledger): RestockResult
    {
        $samePayload = $existing->actor_id === $supervisor->id
            && $existing->amount === $command->amount
            && $existing->note === $command->note;

        if (! $samePayload) {
            throw new IdempotencyKeyConflict($command->idempotencyKey);
        }

        $asOfThen = $ledger->asOf($existing->seq);
        $existing->setRelation('actor', $supervisor);

        $instant = $asOfThen->instantAt($existing->seq) ?? $existing->recorded_at;

        return new RestockResult($existing, $this->calculator->calculateAt($asOfThen, $instant), replayed: true);
    }
}
