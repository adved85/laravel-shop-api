<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(fake()->numberBetween(1, 3), true);

        return [
            'name'      => ucwords($name),
            'slug'      => Str::slug($name),
            'parent_id' => null,
            'in_use'    => true,
            'order'     => $this->faker->numberBetween(0, 100),
        ];
    }
}
