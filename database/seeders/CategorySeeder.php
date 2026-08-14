<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $roots = Category::factory()->count(6)->create();

        $roots->each(function (Category $parent) {
            Category::factory()->count(4)->create(['parent_id' => $parent->id]);
        });
    }
}
