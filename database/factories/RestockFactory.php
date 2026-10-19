<?php

namespace Database\Factories;

use App\Models\Pool;
use App\Models\Restock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Restock>
 */
class RestockFactory extends Factory
{
    /**
     * Define the model's default state: 100 units, no actor, at seq 1.
     * Tests that add more rows to one pool must set `seq` (and the pool's `version`) themselves.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_id' => Pool::factory(),
            'seq' => 1,
            'amount' => 100,
            'actor_id' => null,
            'note' => null,
            'idempotency_key' => null,
            'recorded_at' => now()->toImmutable(),
        ];
    }
}
