<?php

namespace App\Pool\Audit;

use App\Models\Correction;
use App\Models\Pool;
use App\Pool\Calculation\Ledger;
use App\Pool\LedgerRepository;
use App\Support\ApiTime;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Builds the supervisor's audit view (M3 plan §6, FR15, FR16): every correction with the original and corrected
 * amounts, the corrected operator's status before and after, and the stretches that were "overdrawn in hindsight".
 *
 * It reads one consistent view and takes no lock, so it never waits for or blocks a writer.
 */
class AuditService
{
    public function __construct(
        private readonly LedgerRepository $ledgers,
        private readonly HistoryReplay $replay,
        private readonly StatusHistory $statuses,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Pool $pool): array
    {
        return $this->readConsistently(function () use ($pool) {
            $current = Pool::findOrFail($pool->getKey());
            $now = $this->clock->now();
            $ledger = $this->ledgers->load($current);
            $stored = $current->corrections()->with(['supervisor', 'allocation.operator'])->orderBy('seq')->get();

            $points = $this->replay->points($ledger, $now);

            return [
                'version' => $current->version,
                'server_time' => ApiTime::format($now),
                'corrections' => $stored->map(fn (Correction $correction) => $this->row($correction, $ledger, $now))->all(),
                'hindsight' => array_map(fn (HindsightWindow $window) => [
                    'from' => $this->position($window->from),
                    'to' => $window->to === null ? null : $this->position($window->to),
                    'lowest' => $window->lowest,
                    'corrections' => $window->correctionSeqs,
                ], $this->replay->overdrawn($points, $ledger)),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Correction $correction, Ledger $ledger, CarbonImmutable $now): array
    {
        $operator = $correction->allocation->operator;
        $change = $this->statuses->forCorrection($ledger, $correction->seq, $now, $operator->name);

        return [
            'seq' => $correction->seq,
            'allocation_seq' => $correction->allocation->seq,
            'operator' => ['id' => $operator->id, 'name' => $operator->name],
            'kind' => $correction->kind,
            'from' => $correction->from_amount,
            'to' => $correction->new_amount ?? 0,
            'reason' => $correction->reason,
            'supervisor' => ['id' => $correction->supervisor->id, 'name' => $correction->supervisor->name],
            'at' => ApiTime::format($correction->effective_at),
            'original' => ['requested' => $correction->allocation->requested, 'granted' => $correction->allocation->granted],
            'status' => [
                'before' => $change->before,
                'after' => $change->after,
                'points' => array_map(fn (array $point) => [
                    'label' => $point['label'],
                    'seq' => $point['seq'],
                    'at' => ApiTime::format($point['at']),
                    'before' => $point['before'],
                    'after' => $point['after'],
                ], $change->points),
            ],
        ];
    }

    /**
     * @return array{seq: int|null, at: string}
     */
    private function position(HistoryPoint $point): array
    {
        return ['seq' => $point->seq, 'at' => ApiTime::format($point->at)];
    }

    /**
     * Run reads in one transaction that sees a single snapshot of the database (the same rule as SnapshotService).
     *
     * @template T
     *
     * @param  Closure(): T  $reads
     * @return T
     */
    private function readConsistently(Closure $reads): mixed
    {
        return DB::transaction(function () use ($reads) {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $reads();
        });
    }
}
