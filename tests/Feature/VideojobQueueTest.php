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

    public function test_deforum_extension_prefills_from_source_job_and_passes_extend_id(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $sourceJob = Videojob::factory()
            ->for($user, 'user')
            ->state([
                'generator' => 'deforum',
                'model_id' => 99,
                'prompt' => 'old prompt',
                'negative_prompt' => 'old negative',
                'frame_count' => 40,
                'fps' => 8,
                'length' => 5,
                'seed' => 222,
                'denoising' => 0.4,
            ])
            ->create();

        $sourceJob->generation_parameters = json_encode([
            'prompts' => [
                'positive' => 'stored prompt',
                'negative' => 'stored negative',
            ],
            'frame_count' => 72,
            'fps' => 12,
            'length' => 6,
            'seed' => 777,
            'denoising' => 0.55,
        ]);
        $sourceJob->save();

        $videoJob = Videojob::factory()
            ->for($user, 'user')
            ->state(['generator' => 'deforum'])
            ->create([
                'fps' => null,
                'length' => null,
                'frame_count' => null,
            ]);

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/submitDeforum', [
            'videoId' => $videoJob->id,
            'modelId' => $videoJob->model_id,
            'prompt' => 'stored prompt',
            'preset' => 'default',
            'frameCount' => 2,
            'extendFromJobId' => $sourceJob->id,
        ]);

        $response->assertOk();

        $videoJob->refresh();

        $this->assertSame(6, $videoJob->length);
        $this->assertSame(12, $videoJob->fps);
        $this->assertSame(72, $videoJob->frame_count);
        $this->assertSame(777, $videoJob->seed);
        $this->assertSame(0.55, (float) $videoJob->denoising);

        Queue::assertPushed(ProcessDeforumJob::class, function (ProcessDeforumJob $job) use ($videoJob, $sourceJob) {
            return $job->videoJob->id === $videoJob->id
                && $job->extendFromJobId === $sourceJob->id;
        });
    }

    public function test_deforum_extension_rejects_non_deforum_source_job(): void
    {
        $user = User::factory()->create();

        $sourceJob = Videojob::factory()
            ->for($user, 'user')
            ->state(['generator' => 'vid2vid'])
            ->create();

        $targetJob = Videojob::factory()
            ->for($user, 'user')
            ->state(['generator' => 'deforum'])
            ->create();

        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/submitDeforum', [
            'videoId' => $targetJob->id,
            'modelId' => $targetJob->model_id,
            'prompt' => 'extend attempt',
            'preset' => 'default',
            'extendFromJobId' => $sourceJob->id,
        ]);

        $response->assertStatus(422)->assertJson([
            'message' => 'Only deforum jobs can be extended',
        ]);
    }
}
