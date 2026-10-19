<?php

namespace App\Models;

use Database\Factories\RestockFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ledger entry that adds capacity to a pool. The seed is the restock at seq 1.
 */
#[Fillable(['pool_id', 'seq', 'amount', 'actor_id', 'note', 'recorded_at'])]
#[WithoutTimestamps]
// Keep the microseconds of timestamptz(6) columns; the default format drops them.
#[DateFormat('Y-m-d H:i:s.uP')]
class Restock extends Model
{
    /** @use HasFactory<RestockFactory> */
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
            'amount' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
