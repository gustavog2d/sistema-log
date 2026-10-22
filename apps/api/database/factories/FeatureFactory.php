<?php

namespace Database\Factories;

use App\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feature> */
class FeatureFactory extends Factory
{
    protected $model = Feature::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->lexify('func.????'),
            'name' => fake()->words(2, true),
            'description' => null,
            'kind' => 'toggle',
            'default_enabled' => true,
            'default_limit' => null,
            'default_visible' => true,
        ];
    }
}
