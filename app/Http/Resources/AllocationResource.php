<?php

namespace App\Http\Resources;

use App\Models\Allocation;
use App\Pool\AllocationOutcome;
use App\Pool\Config\RulesConfig;
use App\Support\ApiTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stored allocation, as the contract shows it under the "allocation" key (plan §6).
 *
 * @mixin Allocation
 */
class AllocationResource extends JsonResource
{
    public static $wrap = 'allocation';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seq' => $this->seq,
            'operator' => ['id' => $this->operator->id, 'name' => $this->operator->name],
            'type' => $this->type,
            'requested' => $this->requested,
            'granted' => $this->granted,
            'outcome' => AllocationOutcome::fromGrant($this->requested, $this->granted)->value,
            'tier_at_log' => $this->tier_at_log,
            'effective_at' => $this->effective_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'recorded_at' => $this->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'expires_at' => $this->type === 'temporary' && $this->granted > 0
                ? ApiTime::format(app(RulesConfig::class)->expiresAt($this->tier_at_log, $this->effective_at))
                : null,
        ];
    }
}
