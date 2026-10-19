<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state: a bronze operator.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'role' => 'operator',
            'tier' => 'bronze',
        ];
    }

    /**
     * Indicate that the user is an operator of the given tier.
     */
    public function operator(string $tier = 'bronze'): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'operator',
            'tier' => $tier,
        ]);
    }

    /**
     * Indicate that the user is a supervisor (no tier).
     */
    public function supervisor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'supervisor',
            'tier' => null,
        ]);
    }
}
