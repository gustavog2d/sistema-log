<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->lexify('plano-????'),
            'name' => fake()->words(2, true),
            'position' => fake()->numberBetween(1, 9),
            'status' => 'draft',
            'trial_days' => 7,
            'currency' => 'BRL',
            'monthly_price_cents' => null,
            'yearly_discount_percent' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active', 'monthly_price_cents' => 5000, 'yearly_discount_percent' => 20]);
    }
}
