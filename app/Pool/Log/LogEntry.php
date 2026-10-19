<?php

namespace App\Pool\Log;

use App\Models\Allocation;
use App\Models\Correction;
use App\Models\Restock;
use Carbon\CarbonImmutable;

/**
 * One line of a pool's log, whatever table it came from (FR3.1). The payload is plain data, so the same entry can be
 * rendered for the snapshot, the log endpoint and the broadcast.
 */
final readonly class LogEntry
{
    /**
     * @param  int|null  $seq  Null only for a derived expiry, which is not stored.
     * @param  string  $kind  'allocation', 'restock', 'correction' or 'expiry'.
     * @param  array<string, mixed>  $payload  The fields that are specific to the kind.
     */
    public function __construct(
        public ?int $seq,
        public string $kind,
        public CarbonImmutable $at,
        public array $payload,
    ) {}

    /**
     * The operator relation must be loaded.
     *
     * @param  CarbonImmutable|null  $expiresAt  When a temporary grant's units return; null for consumed or zero grants.
     */
    public static function fromAllocation(Allocation $allocation, ?CarbonImmutable $expiresAt = null): self
    {
        return new self($allocation->seq, 'allocation', $allocation->effective_at, [
            'operator' => ['id' => $allocation->operator->id, 'name' => $allocation->operator->name],
            'type' => $allocation->type,
            'requested' => $allocation->requested,
            'granted' => $allocation->granted,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * The actor relation must be loaded. The seeded initial stock has no actor.
     */
    public static function fromRestock(Restock $restock): self
    {
        return new self($restock->seq, 'restock', $restock->recorded_at, [
            'actor' => $restock->actor === null ? null : ['id' => $restock->actor->id, 'name' => $restock->actor->name],
            'amount' => $restock->amount,
            'note' => $restock->note,
        ]);
    }

    /**
     * The supervisor and allocation relations must be loaded. M1 stores no corrections; this serves M3 and fixtures.
     */
    public static function fromCorrection(Correction $correction): self
    {
        return new self($correction->seq, 'correction', $correction->effective_at, [
            'supervisor' => ['id' => $correction->supervisor->id, 'name' => $correction->supervisor->name],
            'allocation_seq' => $correction->allocation->seq,
            'from' => $correction->allocation->granted,
            'to' => $correction->new_amount,
            'reason' => $correction->reason,
        ]);
    }

    /**
     * Expiry is derived and never stored, so it has no seq (FR3.2).
     *
     * @param  CarbonImmutable  $at  The moment the units returned.
     * @param  array{id: int, name: string}  $operator  Who held the units.
     * @param  int  $anchor  The highest stored seq at or before $at: where the entry sits in the log (DD4).
     */
    public static function expiry(CarbonImmutable $at, int $allocationSeq, int $units, array $operator, int $anchor): self
    {
        return new self(null, 'expiry', $at, [
            'allocation_seq' => $allocationSeq,
            'units' => $units,
            'operator' => $operator,
            'anchor' => $anchor,
        ]);
    }

    /**
     * A stable position for paging: "<seq>" for stored entries, "<anchor>x<allocation_seq>" for an expiry (DD4).
     */
    public function cursor(): string
    {
        return $this->kind === 'expiry'
            ? "{$this->payload['anchor']}x{$this->payload['allocation_seq']}"
            : (string) $this->seq;
    }

    /**
     * Newest first means higher keys first. An expiry sits directly above the stored entry it is anchored to.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function sortKey(): array
    {
        return $this->kind === 'expiry'
            ? [$this->payload['anchor'], 1, $this->payload['allocation_seq']]
            : [(int) $this->seq, 0, 0];
    }
}
