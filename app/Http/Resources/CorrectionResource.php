<?php

namespace App\Http\Resources;

use App\Models\Correction;
use App\Support\ApiTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stored correction, as the contract shows it under the "correction" key (M3 plan §6).
 * The supervisor and allocation.operator relations must be loaded.
 *
 * @mixin Correction
 */
class CorrectionResource extends JsonResource
{
    public static $wrap = 'correction';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seq' => $this->seq,
            'allocation_seq' => $this->allocation->seq,
            'operator' => ['id' => $this->allocation->operator->id, 'name' => $this->allocation->operator->name],
            'kind' => $this->kind,
            'from' => $this->from_amount,
            // A void takes the entry to 0 units.
            'to' => $this->new_amount ?? 0,
            'reason' => $this->reason,
            'supervisor' => ['id' => $this->supervisor->id, 'name' => $this->supervisor->name],
            'effective_at' => ApiTime::format($this->effective_at),
        ];
    }
}
