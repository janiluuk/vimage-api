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

class VideojobApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $videoService = Mockery::mock(VideoProcessingService::class);
        $videoService->shouldIgnoreMissing();
        $this->app->instance(VideoProcessingService::class, $videoService);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_status_endpoint_returns_job_progress_snapshot(): void
    {
        $videoJob = Videojob::factory()->create([
            'status' => Videojob::STATUS_PROCESSING,
            'progress' => 42,
            'job_time' => 12,
            'estimated_time_left' => 33,
            'queued_at' => Carbon::parse('2024-01-01 12:00:00'),
        ]);

        $response = $this->getJson("/status/{$videoJob->id}");

        $response->assertOk()->assertJson([
            'status' => Videojob::STATUS_PROCESSING,
            'progress' => 42,
            'job_time' => 12,
            'estimated_time_left' => 33,
            'queued_at' => Carbon::parse('2024-01-01 12:00:00')->timestamp,
            'queue' => [],
        ]);
    }

    public function test_finalize_dispatches_low_priority_queue(): void
    {
        Queue::fake();
        Carbon::setTestNow('2024-02-02 10:00:00');
        putenv('LOW_PRIORITY_QUEUE=slow');

        $videoJob = Videojob::factory()->for(User::factory(), 'user')->create([
            'status' => Videojob::STATUS_PENDING,
            'queued_at' => null,
        ]);

        $this->actingAs($videoJob->user, 'api');

        $response = $this->postJson('/api/finalize', [
            'videoId' => $videoJob->id,
        ]);

        $response->assertOk()->assertJson([
            'status' => Videojob::STATUS_APPROVED,
            'progress' => 0,
            'job_time' => 0,
            'queued_at' => Carbon::parse('2024-02-02 10:00:00')->timestamp,
        ]);

        $videoJob->refresh();
        $this->assertSame(Videojob::STATUS_APPROVED, $videoJob->status);
        $this->assertNotNull($videoJob->queued_at);

        Queue::assertPushed(ProcessVideoJob::class, function (ProcessVideoJob $job) use ($videoJob) {
            return $job->videoJob->id === $videoJob->id && $job->queue === 'slow';
        });
    }

    public function test_finalize_deforum_dispatches_low_priority_queue(): void
    {
        Queue::fake();
        putenv('LOW_PRIORITY_QUEUE=background');

        $videoJob = Videojob::factory()->for(User::factory(), 'user')->create([
            'generator' => 'deforum',
            'status' => Videojob::STATUS_PROCESSING,
        ]);

        $this->actingAs($videoJob->user, 'api');

        $response = $this->postJson('/api/finalizeDeforum', [
            'videoId' => $videoJob->id,
        ]);

        $response->assertOk()->assertJson([
            'status' => Videojob::STATUS_APPROVED,
            'progress' => 0,
        ]);

        Queue::assertPushed(ProcessDeforumJob::class, function (ProcessDeforumJob $job) use ($videoJob) {
            return $job->videoJob->id === $videoJob->id && $job->queue === 'background';
        });
    }

    public function test_cancel_job_updates_status_and_progress(): void
    {
        $videoJob = Videojob::factory()->for(User::factory(), 'user')->create([
            'status' => Videojob::STATUS_PROCESSING,
            'progress' => 37,
            'job_time' => 15,
            'estimated_time_left' => 99,
        ]);

        $this->actingAs($videoJob->user, 'api');

        $response = $this->postJson("/api/cancelJob/{$videoJob->id}", [
            'videoId' => $videoJob->id,
        ]);

        $response->assertOk()->assertJson([
            'status' => Videojob::STATUS_CANCELLED,
            'progress' => 0,
            'job_time' => 0,
            'estimated_time_left' => 0,
        ]);

        $videoJob->refresh();
        $this->assertSame(Videojob::STATUS_CANCELLED, $videoJob->status);
        $this->assertSame(0, $videoJob->progress);
    }

    public function test_non_owner_cannot_finalize_job(): void
    {
        $videoJob = Videojob::factory()->for(User::factory(), 'user')->create();

        $this->actingAs(User::factory()->create(), 'api');

        $response = $this->postJson('/api/finalize', [
            'videoId' => $videoJob->id,
        ]);

        $response->assertStatus(403)->assertJson([
            'error' => 'Unauthorized. Not your video.',
        ]);
    }
}
