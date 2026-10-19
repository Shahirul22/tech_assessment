<?php

namespace App\Pool;

use App\Models\Allocation;
use App\Models\Correction;
use App\Models\Pool;
use App\Models\User;
use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\CorrectionEntry;
use App\Pool\Calculation\Ledger;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Config\RulesConfig;
use App\Pool\Exceptions\AllocationVoided;
use App\Pool\Exceptions\CorrectionRefused;
use App\Pool\Exceptions\IdempotencyKeyConflict;
use App\Pool\Exceptions\PoolBusy;
use App\Pool\Log\LogEntry;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * The only write path for corrections (M3 DD1, DD4-DD6, FR6).
 *
 * A correction is one more ledger row: nothing stored is ever changed. Under the pool's row lock it judges the request
 * against the pool as it stands now. Lowering and voiding free units and are always accepted; a raise is accepted only
 * if the free units cover it (D7), except on a Temporary entry whose window has ended, which changes history only (D11).
 */
class CorrectionService
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
     * @throws ModelNotFoundException when the pool has no allocation with that number
     * @throws IdempotencyKeyConflict when the key was used before for a different supervisor, entry, kind, amount or reason
     * @throws AllocationVoided when the entry was voided before
     * @throws ValidationException when the correction would change nothing (field `amount`: no_change)
     * @throws CorrectionRefused when a raise needs more units than are free
     * @throws PoolBusy when the pool lock could not be taken within 5 seconds
     */
    public function apply(Pool $pool, User $supervisor, ApplyCorrection $command): CorrectionResult
    {
        // Receipt time is read first, before any waiting for the lock (M1 DD13).
        $receipt = $this->clock->now();

        $result = $this->gate->locked($pool, fn (Pool $locked) => $this->applyUnderLock($locked, $supervisor, $command, $receipt));

        // Outside the lock: the operator is only needed to render the entry.
        $result->correction->allocation->loadMissing('operator');

        // The transaction has committed and the lock is released. A replay stored nothing new, so it announces nothing.
        if (! $result->replayed) {
            $this->gate->announce($result->correction->pool_id, $result->pool->version, $result->pool->remaining, LogEntry::fromCorrection($result->correction));
        }

        return $result;
    }

    private function applyUnderLock(Pool $locked, User $supervisor, ApplyCorrection $command, CarbonImmutable $receipt): CorrectionResult
    {
        $existing = $locked->corrections()->where('idempotency_key', $command->idempotencyKey)->with('allocation')->first();
        $ledger = $this->ledgers->load($locked);

        if ($existing !== null) {
            return $this->replay($existing, $supervisor, $command, $ledger);
        }

        $allocation = $locked->allocations()->where('seq', $command->allocationSeq)->firstOrFail();

        $instant = $ledger->evaluationInstant($receipt);
        $state = $this->calculator->calculateAt($ledger, $instant);
        $entry = $this->effectiveEntry($state->effective, $allocation);

        if ($entry->voided) {
            throw new AllocationVoided($allocation->seq);
        }

        $newUnits = $command->kind === 'void' ? 0 : (int) $command->amount;

        if ($newUnits === $entry->units) {
            throw ValidationException::withMessages(['amount' => ['no_change']]);
        }

        if ($newUnits > $entry->units && $this->needsPoolCheck($entry, $instant) && $newUnits - $entry->units > $state->remaining) {
            throw new CorrectionRefused($newUnits - $entry->units, max(0, $state->remaining), $allocation->seq);
        }

        $seq = $this->sequence->next($locked);

        $correction = Correction::create([
            'pool_id' => $locked->id,
            'seq' => $seq,
            'allocation_id' => $allocation->id,
            'supervisor_id' => $supervisor->id,
            'kind' => $command->kind,
            'from_amount' => $entry->units,
            'new_amount' => $command->kind === 'void' ? null : $newUnits,
            'reason' => $command->reason,
            'effective_at' => $receipt,
            'recorded_at' => $this->clock->now(),
            'idempotency_key' => $command->idempotencyKey,
        ]);
        $correction->setRelation('supervisor', $supervisor);
        $correction->setRelation('allocation', $allocation);

        $after = $this->calculator->calculateAt(new Ledger(
            $ledger->restocks,
            $ledger->allocations,
            [...$ledger->corrections, new CorrectionEntry($correction->id, $seq, $allocation->id, $correction->kind, $correction->new_amount, $receipt)],
        ), $instant);

        return new CorrectionResult($correction, $after, replayed: false);
    }

    /**
     * The same key with the same request returns the stored result, with the pool as it stood right after that
     * correction, even if the entry has been corrected again since. A different request under the key is refused.
     */
    private function replay(Correction $existing, User $supervisor, ApplyCorrection $command, Ledger $ledger): CorrectionResult
    {
        $samePayload = $existing->supervisor_id === $supervisor->id
            && $existing->allocation->seq === $command->allocationSeq
            && $existing->kind === $command->kind
            && $existing->new_amount === $command->amount
            && $existing->reason === $command->reason;

        if (! $samePayload) {
            throw new IdempotencyKeyConflict($command->idempotencyKey);
        }

        $asOfThen = $ledger->asOf($existing->seq);
        $existing->setRelation('supervisor', $supervisor);

        $instant = $asOfThen->instantAt($existing->seq) ?? $existing->effective_at;

        return new CorrectionResult($existing, $this->calculator->calculateAt($asOfThen, $instant), replayed: true);
    }

    private function effectiveEntry(Ledger $effective, Allocation $allocation): AllocationEntry
    {
        foreach ($effective->allocations as $entry) {
            if ($entry->id === $allocation->id) {
                return $entry;
            }
        }

        throw (new ModelNotFoundException)->setModel(Allocation::class, [$allocation->id]);
    }

    /**
     * Whether a raise must be covered by the free units. A Temporary entry whose window has ended at the instant has
     * already returned its units, so correcting it changes history only (D7, D11, FR6.5). The window comes from the
     * stored entry, so a zero-grant entry is judged the same way.
     */
    private function needsPoolCheck(AllocationEntry $entry, CarbonImmutable $instant): bool
    {
        return ! ($entry->type === 'temporary'
            && ! $instant->lessThan($this->rules->expiresAt($entry->tierAtLog, $entry->effectiveAt)));
    }
}
