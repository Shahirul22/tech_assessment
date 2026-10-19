<?php

namespace Database\Factories;

use App\Models\Allocation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Allocation>
 */
class AllocationFactory extends Factory
{
    /**
     * Define the model's default state: a fully granted request of 1 unit at seq 1.
     * Tests that add more rows to one pool must set `seq` (and the pool's `version`) themselves.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_id' => Pool::factory(),
            'seq' => 1,
            'operator_id' => User::factory()->operator(),
            'type' => 'consumed',
            'requested' => 1,
            'granted' => 1,
            'tier_at_log' => 'bronze',
            'effective_at' => now()->toImmutable(),
            'recorded_at' => now()->toImmutable(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
