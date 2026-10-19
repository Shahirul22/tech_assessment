<?php

namespace App\Models;

use Database\Factories\CorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supervisor's amend or void of an allocation. Append-only; `from_amount` is the units the entry held right before it.
 */
#[Fillable([
    'pool_id', 'seq', 'allocation_id', 'supervisor_id', 'kind', 'from_amount', 'new_amount',
    'reason', 'effective_at', 'recorded_at', 'idempotency_key',
])]
#[WithoutTimestamps]
// Keep the microseconds of timestamptz(6) columns; the default format drops them.
#[DateFormat('Y-m-d H:i:s.uP')]
class Correction extends Model
{
    /** @use HasFactory<CorrectionFactory> */
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
            'from_amount' => 'integer',
            'new_amount' => 'integer',
            'effective_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
