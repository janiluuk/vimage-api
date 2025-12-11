<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => $this->faker->words(3, true),
            'slug' => $this->faker->unique()->slug(),
            'description' => $this->faker->realText(30),
            'main_image' => $this->faker->imageUrl(100, 100),
            'second_image' => $this->faker->imageUrl(100, 100),
        ];
    }
}
