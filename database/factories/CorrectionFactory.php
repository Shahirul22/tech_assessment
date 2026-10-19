<?php

namespace Database\Factories;

use App\Models\Allocation;
use App\Models\Correction;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Correction>
 */
class CorrectionFactory extends Factory
{
    /**
     * Define the model's default state: an amend of a 1-unit allocation to 1 unit, at seq 2.
     * Tests that add more rows to one pool must set `seq` (and the pool's `version`) themselves.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pool_id' => Pool::factory(),
            'seq' => 2,
            'allocation_id' => Allocation::factory(),
            'supervisor_id' => User::factory()->supervisor(),
            'kind' => 'amend',
            'from_amount' => 2,
            'new_amount' => 1,
            'reason' => 'Counted wrong',
            'effective_at' => now()->toImmutable(),
            'recorded_at' => now()->toImmutable(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * A void: the entry goes to 0 units and has no new amount.
     */
    public function void(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => 'void', 'new_amount' => null]);
    }

    /**
     * An amend to the given units (1 or more).
     */
    public function amend(int $to): static
    {
        return $this->state(fn (array $attributes) => ['kind' => 'amend', 'new_amount' => $to]);
    }
}
