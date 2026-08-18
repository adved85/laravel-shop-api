<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(fake()->numberBetween(1, 3), true);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'in_use' => true,
            'order' => $this->faker->numberBetween(0, 100),
        ];
    }
}
