<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeforumJob;
use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\Videojob;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class VideojobExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    private VideoProcessingService $mockVideoService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->mockVideoService = Mockery::mock(VideoProcessingService::class);
        $this->mockVideoService->shouldIgnoreMissing();
        $this->app->instance(VideoProcessingService::class, $this->mockVideoService);

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test extending a deforum job with extendFromJobId parameter
     */
    public function test_can_extend_deforum_job()
    {
        Queue::fake();

        // Create a base deforum job
        $baseJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'generator' => 'deforum',
            'status' => 'finished',
            'model_id' => 1,
            'prompt' => 'test prompt',
            'negative_prompt' => 'test negative',
            'seed' => 12345,
            'denoising' => 0.5,
            'fps' => 24,
            'frame_count' => 100,
            'length' => 4,
            'width' => 512,
            'height' => 512,
            'generation_parameters' => json_encode([
                'model_id' => 1,
                'prompts' => [
                    'positive' => 'test prompt',
                    'negative' => 'test negative',
                ],
                'seed' => 12345,
                'denoising' => 0.5,
                'fps' => 24,
                'frame_count' => 100,
                'length' => 4,
            ]),
        ]);

        // Create a new job to be extended
        $extendJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'generator' => 'deforum',
            'status' => 'pending',
            'filename' => 'test.png',
            'outfile' => 'test_out.mp4',
        ]);

        // Generate the extended job
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/generate', [
                'videoId' => $extendJob->id,
                'type' => 'deforum',
                'modelId' => 1,
                'prompt' => 'extended prompt',
                'preset' => 'zoom',
                'length' => 4,
                'frameCount' => 5,
                'extendFromJobId' => $baseJob->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id',
            'status',
            'seed',
            'job_time',
            'progress',
            'estimated_time_left',
            'width',
            'height',
            'length',
            'fps',
        ]);

        // Verify the job was updated with the base job's parameters
        $extendJob->refresh();
        $this->assertEquals('processing', $extendJob->status);
        $this->assertEquals($baseJob->model_id, $extendJob->model_id);
        $this->assertEquals($baseJob->fps, $extendJob->fps);
        $this->assertEquals($baseJob->width, $extendJob->width);
        $this->assertEquals($baseJob->height, $extendJob->height);
    }

    /**
     * Test extending a vid2vid job with extendFromJobId parameter
     */
    public function test_can_extend_vid2vid_job()
    {
        Queue::fake();

        // Create a base vid2vid job
        $baseJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'generator' => null,
            'status' => 'finished',
            'model_id' => 1,
            'prompt' => 'test prompt',
            'negative_prompt' => 'test negative',
            'seed' => 12345,
            'denoising' => 0.7,
            'cfg_scale' => 7,
            'fps' => 30,
            'width' => 512,
            'height' => 512,
            'last_frame_path' => '/path/to/last_frame.png',
            'generation_parameters' => json_encode([
                'model_id' => 1,
                'prompt' => 'test prompt',
                'negative_prompt' => 'test negative',
                'seed' => 12345,
                'denoising_strength' => 0.7,
                'cfg_scale' => 7,
                'fps' => 30,
            ]),
        ]);

        // Create a new job to be extended
        $extendJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
            'filename' => 'test.mp4',
            'outfile' => 'test_out.mp4',
        ]);

        // Generate the extended job
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/generate', [
                'videoId' => $extendJob->id,
                'type' => 'vid2vid',
                'modelId' => 1,
                'prompt' => 'extended prompt',
                'cfgScale' => 7,
                'denoising' => 0.7,
                'frameCount' => 5,
                'extendFromJobId' => $baseJob->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id',
            'status',
            'seed',
            'job_time',
            'progress',
            'estimated_time_left',
            'width',
            'height',
            'length',
            'fps',
        ]);

        // Verify the job was updated with the base job's parameters
        $extendJob->refresh();
        $this->assertEquals('processing', $extendJob->status);
        $this->assertEquals($baseJob->model_id, $extendJob->model_id);
        $this->assertEquals($baseJob->width, $extendJob->width);
        $this->assertEquals($baseJob->height, $extendJob->height);
        $this->assertEquals($baseJob->fps, $extendJob->fps);
    }

    /**
     * Test that extending a deforum job with vid2vid is rejected
     */
    public function test_cannot_extend_deforum_job_with_vid2vid()
    {
        Queue::fake();

        // Create a base deforum job
        $baseJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'generator' => 'deforum',
            'status' => 'finished',
            'width' => 512,
            'height' => 512,
        ]);

        // Create a new job to be extended
        $extendJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
            'width' => 512,
            'height' => 512,
        ]);

        // Try to extend with vid2vid (should fail)
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/generate', [
                'videoId' => $extendJob->id,
                'type' => 'vid2vid',
                'modelId' => 1,
                'prompt' => 'extended prompt',
                'cfgScale' => 7,
                'denoising' => 0.7,
                'frameCount' => 5,
                'extendFromJobId' => $baseJob->id,
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Cannot extend deforum jobs with vid2vid',
        ]);
    }

    /**
     * Test that extending a vid2vid job with deforum is rejected
     */
    public function test_cannot_extend_vid2vid_job_with_deforum()
    {
        Queue::fake();

        // Create a base vid2vid job
        $baseJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'generator' => null,
            'status' => 'finished',
            'width' => 512,
            'height' => 512,
        ]);

        // Create a new job to be extended
        $extendJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'generator' => 'deforum',
            'status' => 'pending',
            'width' => 512,
            'height' => 512,
        ]);

        // Try to extend with deforum (should fail)
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/generate', [
                'videoId' => $extendJob->id,
                'type' => 'deforum',
                'modelId' => 1,
                'prompt' => 'extended prompt',
                'preset' => 'zoom',
                'length' => 4,
                'frameCount' => 5,
                'extendFromJobId' => $baseJob->id,
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Only deforum jobs can be extended',
        ]);
    }

    /**
     * Test that user cannot extend another user's job
     */
    public function test_cannot_extend_another_users_job()
    {
        Queue::fake();

        $otherUser = User::factory()->create();

        // Create a base job owned by another user
        $baseJob = Videojob::factory()->create([
            'user_id' => $otherUser->id,
            'generator' => 'deforum',
            'status' => 'finished',
            'width' => 512,
            'height' => 512,
        ]);

        // Create a new job to be extended
        $extendJob = Videojob::factory()->create([
            'user_id' => $this->user->id,
            'generator' => 'deforum',
            'status' => 'pending',
            'width' => 512,
            'height' => 512,
        ]);

        // Try to extend with another user's job (should fail)
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/generate', [
                'videoId' => $extendJob->id,
                'type' => 'deforum',
                'modelId' => 1,
                'prompt' => 'extended prompt',
                'preset' => 'zoom',
                'length' => 4,
                'frameCount' => 5,
                'extendFromJobId' => $baseJob->id,
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'Unauthorized. Not your video.',
        ]);
    }
}
