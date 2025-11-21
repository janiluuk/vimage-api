<?php

namespace Database\Factories;

use App\Models\ModelFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ModelFileFactory extends Factory
{
    protected $model = ModelFile::class;

    public function definition(): array
    {
        return [
            'filename' => $this->faker->lexify('model-????.bin'),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'version' => $this->faker->numerify('v#.#.#'),
            'previewUrl' => $this->faker->imageUrl(),
            'enabled' => true,
        ];
    }
}
