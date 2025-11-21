<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeforumJob;
use App\Jobs\ProcessVideoJob;
use App\Services\DeforumProcessingService;
use App\Services\VideoProcessingService;
use App\Models\User;
use App\Models\Videojob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class VideojobQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->withoutMiddleware();

        $videoService = Mockery::mock(VideoProcessingService::class);
        $videoService->shouldReceive('parseJob')->andReturnUsing(fn ($job) => $job);
        $videoService->shouldReceive('startProcess')->andReturnTrue();
        $this->app->instance(VideoProcessingService::class, $videoService);

        $deforumService = Mockery::mock(DeforumProcessingService::class);
        $deforumService->shouldReceive('startProcess')->andReturnTrue();
        $this->app->instance(DeforumProcessingService::class, $deforumService);
    }

    public function test_submit_uses_high_queue_for_single_frame_jobs(): void
    {
        Queue::fake();
        putenv('HIGH_PRIORITY_QUEUE=critical');

        $videoJob = Videojob::factory()->for(User::factory(), 'user')->create();

        $this->actingAs($videoJob->user, 'api');

        $response = $this->postJson('/api/submit', [
            'videoId' => $videoJob->id,
            'modelId' => $videoJob->model_id,
            'cfgScale' => 7,
            'prompt' => 'test prompt',
            'frameCount' => 1,
            'denoising' => 0.7,
        ]);

        $response->assertOk()->assertJson(['status' => 'processing']);

        Queue::assertPushed(ProcessVideoJob::class, function (ProcessVideoJob $job) use ($videoJob) {
            return $job->videoJob->id === $videoJob->id && $job->queue === 'critical';
        });
    }

    public function test_submit_uses_medium_queue_when_requesting_multiple_frames(): void
    {
        Queue::fake();
        putenv('MEDIUM_PRIORITY_QUEUE=');

        $videoJob = Videojob::factory()->for(User::factory(), 'user')->create();

        $this->actingAs($videoJob->user, 'api');

        $response = $this->postJson('/api/submit', [
            'videoId' => $videoJob->id,
            'modelId' => $videoJob->model_id,
            'cfgScale' => 7,
            'prompt' => 'test prompt',
            'frameCount' => 3,
            'denoising' => 0.7,
        ]);

        $response->assertOk()->assertJson(['status' => 'processing']);

        Queue::assertPushed(ProcessVideoJob::class, function (ProcessVideoJob $job) use ($videoJob) {
            return $job->videoJob->id === $videoJob->id && $job->queue === 'medium';
        });
    }

    public function test_deforum_submission_uses_configured_queue(): void
    {
        Queue::fake();
        putenv('MEDIUM_PRIORITY_QUEUE=priority-med');

        $videoJob = Videojob::factory()
            ->for(User::factory(), 'user')
            ->state(['generator' => 'deforum'])
            ->create();

        $this->actingAs($videoJob->user, 'api');

        $response = $this->postJson('/api/submitDeforum', [
            'videoId' => $videoJob->id,
            'modelId' => $videoJob->model_id,
            'prompt' => 'deforum prompt',
            'frameCount' => 5,
            'preset' => 'default',
            'length' => 2,
        ]);

        $response->assertOk()->assertJson(['status' => 'processing']);

        Queue::assertPushed(ProcessDeforumJob::class, function (ProcessDeforumJob $job) use ($videoJob) {
            return $job->videoJob->id === $videoJob->id && $job->queue === 'priority-med';
        });
    }
}
