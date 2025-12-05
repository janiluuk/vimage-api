<?php

namespace Tests\Unit;

use App\Models\Videojob;
use App\Services\VideoProcessingService;
use FFMpeg\FFMpeg as FFMpegOg;
use FFMpeg\FFProbe;
use Mockery;
use Tests\TestCase;

class VideoProcessingServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function makeService(): VideoProcessingService
    {
        return new VideoProcessingService(Mockery::mock(FFMpegOg::class), Mockery::mock(FFProbe::class));
    }

    public function test_generate_controlnet_params_casts_boolean_flags(): void
    {
        $service = $this->makeService();

        $args = $service->generateControlnetParams([
            ['enabled' => true, 'weight' => 0.55],
            ['enabled' => false, 'guidance_start' => 0.15],
        ]);

        $this->assertSame("'enabled=True, weight=0.55'", $args['unit1_params']);
        $this->assertSame("'enabled=False, guidance_start=0.15'", $args['unit2_params']);
    }

    public function test_apply_prompts_appends_default_suffixes(): void
    {
        config([
            'app.processing.default_prompt_suffix' => 'cinematic lighting',
            'app.processing.default_negative_prompt_suffix' => 'low quality',
        ]);

        $videoJob = Videojob::factory()->make([
            'user_id' => null,
            'model_id' => null,
            'prompt' => 'a test prompt',
            'negative_prompt' => '',
        ]);

        [$prompt, $negativePrompt] = $this->makeService()->applyPrompts($videoJob);

        $this->assertSame('a test prompt, cinematic lighting', $prompt);
        $this->assertSame('low quality', $negativePrompt);
    }

    public function test_get_scaled_size_limits_dimensions(): void
    {
        $service = $this->makeService();

        $wideJob = Videojob::factory()->make([
            'user_id' => null,
            'model_id' => null,
            'width' => 1920,
            'height' => 1080,
        ]);
        $squareJob = Videojob::factory()->make([
            'user_id' => null,
            'model_id' => null,
            'width' => 1024,
            'height' => 1024,
        ]);
        $tallJob = Videojob::factory()->make([
            'user_id' => null,
            'model_id' => null,
            'width' => 640,
            'height' => 1280,
        ]);

        $this->assertSame([960, 540], $service->getScaledSize($wideJob));
        $this->assertSame([500, 500], $service->getScaledSize($squareJob));
        $this->assertSame([480, 960], $service->getScaledSize($tallJob));
    }
}
