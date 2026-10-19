<?php

namespace App\Pool;

use App\Models\Pool;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only place a pool's ledger numbers are handed out (plan §5 "Seq rule").
 *
 * Restocks, allocations and corrections share one sequence per pool, and the log and the live screens identify an
 * entry by its seq. The database only keeps seq unique inside each table, so nothing but this class may take the next
 * number: every writer (an allocation, and later M3's restocks and corrections) calls next() inside the pool's
 * transaction, after taking the pool lock, and uses the number it gets.
 *
 * It is one atomic UPDATE ... RETURNING, so two writers cannot be given the same number even if one of them forgot the
 * lock, and the number is rolled back with the transaction, so a failed write leaves no gap.
 */
class LedgerSequence
{
    /**
     * Take the next number for the pool and store it as the pool's version.
     *
     * @throws LogicException when called outside a transaction
     * @throws ModelNotFoundException when the pool does not exist
     */
    public function next(Pool $pool): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A ledger number must be taken inside the pool\'s transaction, so it commits or rolls back with the entry it numbers.');
        }

        $row = DB::selectOne(
            'update pools set version = version + 1, updated_at = now() where id = ? returning version',
            [$pool->getKey()],
        );

        if ($row === null) {
            throw (new ModelNotFoundException)->setModel(Pool::class, [$pool->getKey()]);
        }

        $seq = (int) $row->version;

        // Bring the model in memory up to date, so the caller never works from a stale version.
        $pool->setAttribute('version', $seq);
        $pool->syncOriginalAttribute('version');

        return $seq;
    }
}
