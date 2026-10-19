<?php

namespace App\Pool;

use App\Events\PoolUpdated;
use App\Http\Resources\LogEntryResource;
use App\Models\Pool;
use App\Pool\Exceptions\PoolBusy;
use App\Pool\Log\LogEntry;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * The one place that keeps writers to a pool one at a time (M3 DD1).
 *
 * Operator submits, restocks and corrections all run their work through locked(), so commit order is the order
 * requests are served in. After the commit they call announce() to tell the open screens.
 */
class PoolWriteGate
{
    /** Postgres SQLSTATE lock_not_available, raised when lock_timeout expires. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    /**
     * Run `$work` in a transaction that holds the pool's row lock, and hand it the freshly read pool.
     *
     * @template T
     *
     * @param  Closure(Pool): T  $work
     * @return T
     *
     * @throws PoolBusy when the pool lock could not be taken within 5 seconds
     */
    public function locked(Pool $pool, Closure $work): mixed
    {
        try {
            return DB::transaction(function () use ($pool, $work) {
                // Isolation stays at Postgres's default, READ COMMITTED. After the lock is granted every statement sees
                // all committed rows, so the ledger read inside $work is exact. REPEATABLE READ would read a snapshot
                // taken before the wait for the lock and could miss a request committed in the meantime.
                DB::statement("SET LOCAL lock_timeout = '5s'");

                $locked = Pool::query()->whereKey($pool->getKey())->lockForUpdate()->firstOrFail();

                return $work($locked);
            });
        } catch (QueryException $e) {
            throw $this->isLockTimeout($e) ? new PoolBusy($e) : $e;
        }
    }

    /**
     * Tell every open screen about the new entry (DD7, FR11).
     *
     * It runs after the response is sent, so it never delays the request or holds the lock. A broadcaster that is down
     * is reported and swallowed: the entry is already stored and the screens catch up by polling. Call it only after
     * the transaction has committed, and never for a replay.
     */
    public function announce(int $poolId, int $version, int $remaining, LogEntry $entry): void
    {
        $event = new PoolUpdated(
            poolId: $poolId,
            version: $version,
            remaining: $remaining,
            entry: LogEntryResource::make($entry)->resolve(),
        );

        defer(function () use ($event) {
            rescue(function () use ($event) {
                // A statement of its own: the pending broadcast sends when it goes out of scope, inside rescue().
                broadcast($event);
            }, report: true);
        });
    }

    private function isLockTimeout(QueryException $e): bool
    {
        return (string) $e->getPrevious()?->getCode() === self::LOCK_NOT_AVAILABLE;
    }
}
