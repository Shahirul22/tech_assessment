<?php

namespace App\Pool\Log;

use App\Models\Allocation;
use App\Models\Pool;
use App\Pool\Calculation\AllocationEntry;
use App\Pool\Calculation\Expiries;
use App\Pool\Calculation\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads a pool's log: restocks, allocations and corrections as one list, newest first (DD4, FR3), with the derived
 * expiry entries merged in at the place they happened (FR3.2).
 *
 * The caller passes the effective ledger (after corrections and expiry, at `$now`). An allocation shows its original
 * request and grant plus what a correction made of it, and the expiry entries and `expires_at` follow the corrected
 * units (M3 DD12): a void leaves no expiry entry, a lowered entry shows the lowered units.
 */
class LogQuery
{
    public function __construct(private readonly Expiries $expiries) {}

    /**
     * @param  string|null  $cursor  Only entries strictly older than this position: a bare seq ("12") or an
     *                               expiry position ("12x7"), as given by an entry's cursor.
     * @param  Ledger  $effective  The pool's ledger after corrections and expiry at `$now` (PoolState::effective).
     * @return array{0: list<LogEntry>, 1: bool} The entries, and whether older ones remain.
     */
    public function latest(Pool $pool, int $limit, CarbonImmutable $now, ?string $cursor, Ledger $effective): array
    {
        $effectiveById = [];

        foreach ($effective->allocations as $entry) {
            $effectiveById[$entry->id] = $entry;
        }

        [$before, $inclusive] = $this->storedBound($cursor);

        // Each table can supply at most $limit + 1 of the newest entries, so the merged list is exact and the
        // extra one tells whether more remain.
        $take = $limit + 1;
        $olderStored = fn ($query) => $query->when(
            $before !== null,
            fn ($query) => $query->where('seq', $inclusive ? '<=' : '<', $before),
        );

        $restocks = $pool->restocks()->with('actor')
            ->tap($olderStored)
            ->orderByDesc('seq')->limit($take)->get()
            ->map(fn ($restock) => LogEntry::fromRestock($restock));

        $allocations = $pool->allocations()->with('operator')
            ->tap($olderStored)
            ->orderByDesc('seq')->limit($take)->get()
            ->map(fn ($allocation) => $this->allocationEntry($allocation, $effectiveById[$allocation->id] ?? null));

        $corrections = $pool->corrections()->with(['supervisor', 'allocation.operator'])
            ->tap($olderStored)
            ->orderByDesc('seq')->limit($take)->get()
            ->map(fn ($correction) => LogEntry::fromCorrection($correction));

        $merged = $restocks->concat($allocations)->concat($corrections)->concat($this->expiries($pool, $effective))
            ->filter(fn (LogEntry $entry) => $this->isOlderThan($entry, $cursor))
            ->sort(fn (LogEntry $a, LogEntry $b) => $b->sortKey() <=> $a->sortKey())
            ->values();

        return [$merged->take($limit)->all(), $merged->count() > $limit];
    }

    private function allocationEntry(Allocation $allocation, ?AllocationEntry $effective): LogEntry
    {
        if ($effective === null) {
            return LogEntry::fromAllocation($allocation);
        }

        return LogEntry::fromAllocation(
            $allocation,
            $effective->expiresAt,
            $effective->correctedFrom === null ? null : $effective->units,
            $effective->voided,
        );
    }

    /**
     * One expiry entry for every temporary grant that held units and whose window has ended, with the corrected units.
     *
     * @return list<LogEntry>
     */
    private function expiries(Pool $pool, Ledger $effective): array
    {
        $lapsed = $this->expiries->expired($effective);

        if ($lapsed === []) {
            return [];
        }

        $anchors = $this->anchors($pool, $lapsed);
        $names = DB::table('users')->whereIn('id', array_unique(array_map(fn (AllocationEntry $entry) => $entry->operatorId, $lapsed)))->pluck('name', 'id');

        return array_map(
            fn (AllocationEntry $entry) => LogEntry::expiry(
                $entry->expiresAt,
                $entry->seq,
                $entry->units,
                ['id' => $entry->operatorId, 'name' => $names[$entry->operatorId]],
                $anchors[$entry->id],
            ),
            $lapsed,
        );
    }

    /**
     * Where each expiry sits: the highest stored seq whose time is at or before the expiry (0 when none), by one query.
     *
     * @param  list<AllocationEntry>  $lapsed
     * @return array<int, int> Anchor seq by allocation id.
     */
    private function anchors(Pool $pool, array $lapsed): array
    {
        $values = implode(', ', array_fill(0, count($lapsed), '(?::bigint, ?::timestamptz)'));
        // The SELECT list's placeholders come first in the SQL text: the pool id three times, then the value pairs.
        $bindings = [$pool->id, $pool->id, $pool->id];

        foreach ($lapsed as $entry) {
            $bindings[] = $entry->id;
            $bindings[] = $entry->expiresAt->utc()->format('Y-m-d H:i:s.uP');
        }

        $rows = DB::select(
            "SELECT v.allocation_id, COALESCE(GREATEST(
                (SELECT max(seq) FROM allocations a WHERE a.effective_at <= v.expires_at AND a.pool_id = ?),
                (SELECT max(seq) FROM restocks r WHERE r.recorded_at <= v.expires_at AND r.pool_id = ?),
                (SELECT max(seq) FROM corrections c WHERE c.effective_at <= v.expires_at AND c.pool_id = ?)
            ), 0) AS anchor
            FROM (VALUES {$values}) AS v(allocation_id, expires_at)",
            $bindings,
        );

        $anchors = [];

        foreach ($rows as $row) {
            $anchors[(int) $row->allocation_id] = (int) $row->anchor;
        }

        return $anchors;
    }

    /**
     * The bound for the stored tables: entries with seq below it, or at or below it for an expiry cursor, because
     * every stored entry up to the anchor is older than an expiry anchored there.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function storedBound(?string $cursor): array
    {
        if ($cursor === null) {
            return [null, false];
        }

        if (str_contains($cursor, 'x')) {
            return [(int) explode('x', $cursor)[0], true];
        }

        return [(int) $cursor, false];
    }

    private function isOlderThan(LogEntry $entry, ?string $cursor): bool
    {
        if ($cursor === null) {
            return true;
        }

        $position = str_contains($cursor, 'x')
            ? [(int) explode('x', $cursor)[0], 1, (int) explode('x', $cursor)[1]]
            : [(int) $cursor, 0, 0];

        return $entry->sortKey() < $position;
    }
}
