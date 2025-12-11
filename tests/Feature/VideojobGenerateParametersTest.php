<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeforumJob;
use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\Videojob;
use App\Services\VideoProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class VideojobGenerateParametersTest extends TestCase
{
    use RefreshDatabase;

    private VideoProcessingService $mockVideoService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->mockVideoService = Mockery::mock(VideoProcessingService::class);
        $this->mockVideoService->shouldIgnoreMissing();
        $this->app->instance(VideoProcessingService::class, $this->mockVideoService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generate_vid2vid_with_minimum_parameters(): void
    {
        Queue::fake();
        
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_PENDING,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 7,
            'prompt' => 'beautiful landscape',
            'denoising' => 0.5,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'id', 'status', 'seed', 'job_time', 'progress', 
            'estimated_time_left', 'width', 'height', 'length', 'fps'
        ]);

        $videoJob->refresh();
        $this->assertSame(Videojob::STATUS_PROCESSING, $videoJob->status);
        $this->assertSame('beautiful landscape', $videoJob->prompt);
        $this->assertSame(7, $videoJob->cfg_scale);
        $this->assertSame(0.5, $videoJob->denoising);
        
        Queue::assertPushed(ProcessVideoJob::class);
    }

    public function test_generate_vid2vid_with_all_parameters(): void
    {
        Queue::fake();
        config(['queue.names.MEDIUM_PRIORITY_QUEUE' => 'normal']);
        
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_PENDING,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 2,
            'cfgScale' => 9,
            'prompt' => 'cyberpunk city at night',
            'negative_prompt' => 'blurry, low quality',
            'frameCount' => 15,
            'denoising' => 0.75,
            'seed' => 42,
            'controlnet' => [
                'type' => 'canny',
                'strength' => 0.8,
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'seed' => 42,
            'status' => Videojob::STATUS_PROCESSING,
        ]);

        $videoJob->refresh();
        $this->assertSame('cyberpunk city at night', $videoJob->prompt);
        $this->assertSame('blurry, low quality', $videoJob->negative_prompt);
        $this->assertSame(9, $videoJob->cfg_scale);
        $this->assertSame(0.75, $videoJob->denoising);
        $this->assertSame(42, $videoJob->seed);
        $this->assertNotNull($videoJob->controlnet);
        
        $controlnet = json_decode($videoJob->controlnet, true);
        $this->assertSame('canny', $controlnet['type']);
        $this->assertSame(0.8, $controlnet['strength']);
    }

    public function test_generate_vid2vid_validates_cfg_scale_range(): void
    {
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create();

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 15, // Invalid: max is 10
            'prompt' => 'test',
            'denoising' => 0.5,
        ]);

        $response->assertStatus(422);
        // Check for validation error in response
        $this->assertTrue(
            $response->json('error.validator.cfgScale') !== null || 
            $response->json('errors.cfgScale') !== null,
            'Expected cfgScale validation error'
        );
    }

    public function test_generate_vid2vid_validates_denoising_range(): void
    {
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create();

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 7,
            'prompt' => 'test',
            'denoising' => 1.5, // Invalid: max is 1.0
        ]);

        $response->assertStatus(422);
        // Check for validation error in response
        $this->assertTrue(
            $response->json('error.validator.denoising') !== null || 
            $response->json('errors.denoising') !== null,
            'Expected denoising validation error'
        );
    }

    public function test_generate_vid2vid_dispatches_to_high_priority_queue_for_single_frame(): void
    {
        Queue::fake();
        config(['queue.names.HIGH_PRIORITY_QUEUE' => 'urgent']);
        
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create();

        $this->actingAs($user, 'api');

        $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 7,
            'prompt' => 'test',
            'frameCount' => 1,
            'denoising' => 0.5,
        ]);

        Queue::assertPushed(ProcessVideoJob::class, function (ProcessVideoJob $job) {
            return $job->queue === 'urgent';
        });
    }

    public function test_generate_vid2vid_dispatches_to_medium_priority_queue_for_multiple_frames(): void
    {
        Queue::fake();
        config(['queue.names.MEDIUM_PRIORITY_QUEUE' => 'normal']);
        
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create();

        $this->actingAs($user, 'api');

        $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 7,
            'prompt' => 'test',
            'frameCount' => 10,
            'denoising' => 0.5,
        ]);

        Queue::assertPushed(ProcessVideoJob::class, function (ProcessVideoJob $job) {
            return $job->queue === 'normal';
        });
    }

    public function test_generate_deforum_with_minimum_parameters(): void
    {
        Queue::fake();
        
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_PENDING,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'deforum',
            'modelId' => 1,
            'prompt' => 'flowing water',
            'preset' => 'default',
            'length' => 4,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'id', 'status', 'seed', 'job_time', 'progress',
            'estimated_time_left', 'width', 'height', 'length', 'fps'
        ]);

        $videoJob->refresh();
        $this->assertSame(Videojob::STATUS_PROCESSING, $videoJob->status);
        $this->assertSame('deforum', $videoJob->generator);
        $this->assertSame('flowing water', $videoJob->prompt);
        $this->assertSame(4, $videoJob->length);
        $this->assertSame(24, $videoJob->fps);
        $this->assertSame(96, $videoJob->frame_count); // 4 * 24
        
        Queue::assertPushed(ProcessDeforumJob::class);
    }

    public function test_generate_deforum_with_all_parameters(): void
    {
        Queue::fake();
        
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_PENDING,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'deforum',
            'modelId' => 3,
            'prompt' => 'abstract art morphing',
            'negative_prompt' => 'static, boring',
            'preset' => 'cinematic',
            'length' => 8,
            'frameCount' => 5,
            'seed' => 123,
            'denoising' => 0.6,
        ]);

        $response->assertOk();
        $response->assertJson([
            'seed' => 123,
            'length' => 8,
            'fps' => 24,
        ]);

        $videoJob->refresh();
        $this->assertSame('abstract art morphing', $videoJob->prompt);
        $this->assertSame('static, boring', $videoJob->negative_prompt);
        $this->assertSame(123, $videoJob->seed);
        $this->assertSame(0.6, $videoJob->denoising);
        $this->assertSame(192, $videoJob->frame_count); // 8 * 24
    }

    public function test_generate_deforum_validates_length_range(): void
    {
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create();

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'deforum',
            'modelId' => 1,
            'prompt' => 'test',
            'preset' => 'default',
            'length' => 25, // Invalid: max is 20
        ]);

        $response->assertStatus(422);
        // Check for validation error in response
        $this->assertTrue(
            $response->json('error.validator.length') !== null || 
            $response->json('errors.length') !== null,
            'Expected length validation error'
        );
    }

    public function test_generate_deforum_can_extend_from_existing_job(): void
    {
        Queue::fake();
        
        $user = User::factory()->create();
        
        $baseJob = Videojob::factory()->for($user, 'user')->create([
            'generator' => 'deforum',
            'status' => Videojob::STATUS_FINISHED,
            'model_id' => 5,
            'prompt' => 'original prompt',
            'negative_prompt' => 'original negative',
            'seed' => 999,
            'fps' => 30,
            'length' => 5,
            'generation_parameters' => json_encode([
                'model_id' => 5,
                'prompts' => [
                    'positive' => 'original prompt',
                    'negative' => 'original negative',
                ],
                'seed' => 999,
                'fps' => 30,
                'length' => 5,
            ]),
        ]);

        $videoJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_PENDING,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'deforum',
            'modelId' => 1, // This will be overridden by baseJob's model
            'prompt' => 'new prompt', // This will override
            'preset' => 'default',
            'length' => 3,
            'extendFromJobId' => $baseJob->id,
        ]);

        $response->assertOk();

        $videoJob->refresh();
        $this->assertSame(5, $videoJob->model_id); // From baseJob
        $this->assertSame('new prompt', $videoJob->prompt); // Overridden by request
        $this->assertSame('original negative', $videoJob->negative_prompt); // From baseJob
        $this->assertSame(999, $videoJob->seed); // From baseJob
        
        Queue::assertPushed(ProcessDeforumJob::class, function (ProcessDeforumJob $job) use ($baseJob) {
            return $job->extendFromJobId === $baseJob->id;
        });
    }

    public function test_generate_deforum_cannot_extend_from_vid2vid_job(): void
    {
        $user = User::factory()->create();
        
        $baseJob = Videojob::factory()->for($user, 'user')->create([
            'generator' => 'vid2vid', // Not deforum
            'status' => Videojob::STATUS_FINISHED,
        ]);

        $videoJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_PENDING,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'deforum',
            'modelId' => 1,
            'prompt' => 'test',
            'preset' => 'default',
            'length' => 3,
            'extendFromJobId' => $baseJob->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Only deforum jobs can be extended']);
    }

    public function test_generate_deforum_cannot_extend_from_other_users_job(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        
        $baseJob = Videojob::factory()->for($user1, 'user')->create([
            'generator' => 'deforum',
            'status' => Videojob::STATUS_FINISHED,
        ]);

        $videoJob = Videojob::factory()->for($user2, 'user')->create([
            'status' => Videojob::STATUS_PENDING,
        ]);

        $this->actingAs($user2, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'deforum',
            'modelId' => 1,
            'prompt' => 'test',
            'preset' => 'default',
            'length' => 3,
            'extendFromJobId' => $baseJob->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Unauthorized. Not your video.']);
    }

    public function test_generate_requires_authentication(): void
    {
        $videoJob = Videojob::factory()->create();

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 7,
            'prompt' => 'test',
            'denoising' => 0.5,
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthenticated']);
    }

    public function test_generate_prevents_non_owner_from_generating(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        
        $videoJob = Videojob::factory()->for($owner, 'user')->create();

        $this->actingAs($otherUser, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 7,
            'prompt' => 'test',
            'denoising' => 0.5,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Unauthorized. Not your video.']);
    }

    public function test_generate_rejects_unsupported_type(): void
    {
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create();

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'invalid_type',
            'modelId' => 1,
            'prompt' => 'test',
        ]);

        $response->assertStatus(422);
        // Check for validation error in response
        $this->assertTrue(
            $response->json('error.validator.type') !== null || 
            $response->json('errors.type') !== null,
            'Expected type validation error'
        );
    }

    public function test_generate_calculates_estimated_time_correctly(): void
    {
        Queue::fake();
        
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create();

        $this->actingAs($user, 'api');

        $frameCount = 10;
        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 7,
            'prompt' => 'test',
            'frameCount' => $frameCount,
            'denoising' => 0.5,
        ]);

        $response->assertOk();
        
        $expectedTime = ($frameCount * 6) + 6;
        $response->assertJson([
            'estimated_time_left' => $expectedTime,
        ]);
    }

    public function test_generate_normalizes_negative_seed_to_random(): void
    {
        Queue::fake();
        
        $user = User::factory()->create();
        $videoJob = Videojob::factory()->for($user, 'user')->create();

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/generate', [
            'videoId' => $videoJob->id,
            'type' => 'vid2vid',
            'modelId' => 1,
            'cfgScale' => 7,
            'prompt' => 'test',
            'seed' => -1,
            'denoising' => 0.5,
        ]);

        $response->assertOk();
        
        $seed = $response->json('seed');
        $this->assertGreaterThan(0, $seed);
        $this->assertLessThanOrEqual(4294967295, $seed);
    }
}
