<?php

namespace App\Models;

use Database\Factories\PoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'version'])]
class Pool extends Model
{
    /** @use HasFactory<PoolFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function restocks(): HasMany
    {
        return $this->hasMany(Restock::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(Correction::class);
    }
}
