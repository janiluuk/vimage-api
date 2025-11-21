<?php

namespace Database\Factories;

use App\Models\ModelFile;
use App\Models\Videojob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideojobFactory extends Factory
{
    protected $model = Videojob::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'model_id' => ModelFile::factory(),
            'filename' => $this->faker->lexify('upload-????.mp4'),
            'original_filename' => $this->faker->lexify('source-????.mp4'),
            'outfile' => $this->faker->lexify('output-????.mp4'),
            'prompt' => $this->faker->sentence(),
            'cfg_scale' => 7,
            'negative_prompt' => '',
            'seed' => $this->faker->numberBetween(1, 99999),
            'frame_count' => $this->faker->numberBetween(1, 10),
            'denoising' => 0.55,
            'progress' => 0,
            'job_time' => 0,
            'status' => Videojob::STATUS_PENDING,
            'generator' => 'vid2vid',
            'mimetype' => 'video/mp4',
        ];
    }
}
