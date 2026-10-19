<?php

namespace App\Http\Resources;

use App\Pool\AllocationOutcome;
use App\Pool\Log\LogEntry;
use App\Support\ApiTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders a log entry in one of the four shapes of plan §6.
 *
 * @mixin LogEntry
 */
class LogEntryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = [
            'seq' => $this->seq,
            'cursor' => $this->cursor(),
            'kind' => $this->kind,
            'at' => $this->at->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];

        $p = $this->payload;

        return $base + match ($this->kind) {
            'allocation' => [
                'operator' => $p['operator'],
                'type' => $p['type'],
                'requested' => $p['requested'],
                'granted' => $p['granted'],
                'outcome' => AllocationOutcome::fromGrant($p['requested'], $p['granted'])->value,
                'expires_at' => $p['expires_at'] === null ? null : ApiTime::format($p['expires_at']),
                'corrected_to' => $p['corrected_to'],
                'voided' => $p['voided'],
            ],
            'restock' => [
                'actor' => $p['actor'],
                'amount' => $p['amount'],
                'note' => $p['note'],
            ],
            'correction' => [
                'supervisor' => $p['supervisor'],
                'operator' => $p['operator'],
                'allocation_seq' => $p['allocation_seq'],
                'correction_kind' => $p['correction_kind'],
                'change' => ['from' => $p['from'], 'to' => $p['to']],
                'reason' => $p['reason'],
            ],
            'expiry' => [
                'operator' => $p['operator'],
                'allocation_seq' => $p['allocation_seq'],
                'units' => $p['units'],
            ],
        };
    }
}
