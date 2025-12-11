<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\User;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'status' => $this->faker->randomElement(['published', 'draft', 'archive']),
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'excerpt' => $this->faker->sentence(10),
            'description' => $this->faker->paragraph(),
            'image' => null,
            'is_on_homepage' => false,
            'date_at' => now(),
        ];
    }
}
