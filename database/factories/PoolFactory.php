<?php

namespace Database\Factories;

use App\Models\Pool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pool>
 */
class PoolFactory extends Factory
{
    /**
     * Define the model's default state: an empty pool. Add capacity with a RestockFactory row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'version' => 0,
        ];
    }
}
