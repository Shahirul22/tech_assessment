<?php

namespace App\Models;

use Database\Factories\AllocationFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ledger entry for one request. `granted` may be 0: a stale request is recorded too.
 */
#[Fillable([
    'pool_id', 'seq', 'operator_id', 'type', 'requested', 'granted',
    'tier_at_log', 'effective_at', 'recorded_at', 'idempotency_key',
])]
#[WithoutTimestamps]
// Keep the microseconds of timestamptz(6) columns; the default format drops them.
#[DateFormat('Y-m-d H:i:s.uP')]
class Allocation extends Model
{
    /** @use HasFactory<AllocationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'requested' => 'integer',
            'granted' => 'integer',
            'effective_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
