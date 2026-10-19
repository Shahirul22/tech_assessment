<?php

namespace App\Http\Resources;

use App\Models\Restock;
use App\Support\ApiTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stored restock, as the contract shows it under the "restock" key (M3 plan §6). The actor relation must be loaded.
 *
 * @mixin Restock
 */
class RestockResource extends JsonResource
{
    public static $wrap = 'restock';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seq' => $this->seq,
            'actor' => ['id' => $this->actor->id, 'name' => $this->actor->name],
            'amount' => $this->amount,
            'note' => $this->note,
            'recorded_at' => ApiTime::format($this->recorded_at),
        ];
    }
}
