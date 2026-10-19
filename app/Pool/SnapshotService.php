<?php

namespace App\Pool;

use App\Http\Resources\LogEntryResource;
use App\Models\Pool;
use App\Models\User;
use App\Pool\Calculation\Expiries;
use App\Pool\Calculation\PoolCalculator;
use App\Pool\Log\LogEntry;
use App\Pool\Log\LogQuery;
use App\Support\ApiTime;
use App\Support\Clock;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Builds the snapshot a screen loads, reloads after a gap, or polls (FR2, FR3, DD2).
 */
class SnapshotService
{
    public function __construct(
        private readonly LedgerRepository $ledgers,
        private readonly PoolCalculator $calculator,
        private readonly LogQuery $log,
        private readonly Clock $clock,
        private readonly ViewerView $viewers,
        private readonly Expiries $expiries,
    ) {}

    /**
     * Version, remaining and the log page all come from one consistent view, so they always agree.
     * No lock is taken: a snapshot never waits for, or blocks, a writer.
     *
     * @return array{pool: array{id: int, name: string}, version: int, remaining: int, log: list<array<string, mixed>>, log_has_more: bool, server_time: string, next_expiry_at: string|null, viewer: array{operator_id: int|null, status: string|null, tier: string|null, window_seconds: int|null, holdings: list<array{allocation_seq: int, units: int, expires_at: string}>}}
     */
    public function build(Pool $pool, ?User $viewer, int $limit = 100): array
    {
        return $this->readConsistently(function () use ($pool, $viewer, $limit) {
            $current = Pool::findOrFail($pool->getKey());
            $now = $this->clock->now();
            $state = $this->calculator->calculateAt($this->ledgers->load($current), $now);
            $nextDue = $state->effective === null ? null : $this->expiries->nextDue($state->effective);
            [$entries, $hasMore] = $this->log->latest($current, $limit, $now);

            return [
                'pool' => ['id' => $current->id, 'name' => $current->name],
                'version' => $current->version,
                'remaining' => $state->remaining,
                'log' => $this->rendered($entries),
                'log_has_more' => $hasMore,
                'server_time' => ApiTime::format($now),
                'next_expiry_at' => $nextDue === null ? null : ApiTime::format($nextDue),
                'viewer' => $this->viewers->for($state, $viewer),
            ];
        });
    }

    /**
     * One page of the log ("Show older"), read from a single consistent view like the snapshot.
     *
     * @return array{entries: list<array<string, mixed>>, has_more: bool}
     */
    public function logPage(Pool $pool, int $limit, ?string $before): array
    {
        return $this->readConsistently(function () use ($pool, $limit, $before) {
            [$entries, $hasMore] = $this->log->latest($pool, $limit, $this->clock->now(), $before);

            return ['entries' => $this->rendered($entries), 'has_more' => $hasMore];
        });
    }

    /**
     * Run reads in one transaction that sees a single snapshot of the database.
     *
     * Postgres only accepts SET TRANSACTION ISOLATION LEVEL before the first query of a transaction, so it is set when
     * this call is the one that opened it. A caller that already opened a transaction owns the isolation level.
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

    /**
     * @param  list<LogEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function rendered(array $entries): array
    {
        return array_map(fn (LogEntry $entry) => LogEntryResource::make($entry)->resolve(), $entries);
    }
}
