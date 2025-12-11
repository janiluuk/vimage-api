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
        config(['queue.names.LOW_PRIORITY_QUEUE' => 'slow']);

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
        config(['queue.names.LOW_PRIORITY_QUEUE' => 'background']);

        $videoJob = Videojob::factory()->for(User::factory(), 'user')->create([
            'generator' => 'deforum',
            'status' => Videojob::STATUS_PROCESSING,
        ]);

        $this->actingAs($videoJob->user, 'api');

        $response = $this->postJson('/api/finalize', [
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

    

    public function test_queue_endpoint_returns_user_jobs(): void
    {
        $user = User::factory()->create();

        $ownedJobs = Videojob::factory()
            ->count(3)
            ->for($user, 'user')
            ->create();

        // Jobs for other users should not be included
        Videojob::factory()->count(2)->create();

        $this->actingAs($user, 'api');

        $response = $this->getJson('/api/queue');

        $response->assertOk();
        $response->assertJsonCount(3);
    }
public function test_status_includes_queue_snapshot_for_approved_job(): void
    {
        Carbon::setTestNow('2024-05-05 12:00:00');

        $approvedJob = Videojob::factory()->create([
            'status' => Videojob::STATUS_APPROVED,
            'queued_at' => Carbon::now()->timestamp,
            'frame_count' => 5,
        ]);

        Videojob::factory()->create([
            'status' => Videojob::STATUS_PROCESSING,
        ]);

        Videojob::factory()->create([
            'status' => Videojob::STATUS_APPROVED,
            'queued_at' => Carbon::now()->addMinute()->timestamp,
        ]);

        $response = $this->getJson("/status/{$approvedJob->id}");

        $response->assertOk();

        $response->assertJson(fn ($json) => $json
                ->where('status', Videojob::STATUS_APPROVED)
                ->where('queued_at', Carbon::now()->timestamp)
                ->has('queue', fn ($queue) => $queue
                    ->where('total_jobs_processing', 1)
                    ->where('total_jobs_in_queue', 2)
                ->where('your_position', 1)
                    ->where('your_estimated_time', 50)
                    ->etc()
            )
            ->etc()
        );

        Carbon::setTestNow();
    }

    public function test_non_owner_cannot_finalize_deforum_job(): void
    {
        $videoJob = Videojob::factory()->for(User::factory(), 'user')->create([
            'generator' => 'deforum',
            'status' => Videojob::STATUS_PROCESSING,
        ]);

        $this->actingAs(User::factory()->create(), 'api');

        $response = $this->postJson('/api/finalize', [
            'videoId' => $videoJob->id,
        ]);

        $response->assertStatus(403)->assertJson([
            'error' => 'Unauthorized. Not your video.',
        ]);
    }

    public function test_processing_status_endpoint_exposes_counts_and_user_jobs(): void
    {
        Carbon::setTestNow('2024-03-01 10:00:00');

        $user = User::factory()->create();
        $processingJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_PROCESSING,
            'progress' => 25,
            'queued_at' => Carbon::now()->timestamp,
        ]);
        $queuedJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_APPROVED,
            'queued_at' => Carbon::now()->addMinute()->timestamp,
            'frame_count' => 12,
        ]);

        Videojob::factory()->create(['status' => Videojob::STATUS_PROCESSING]);
        Videojob::factory()->create(['status' => Videojob::STATUS_APPROVED]);

        $this->actingAs($user, 'api');

        $response = $this->getJson('/api/video-jobs/processing/status');

        $response->assertOk()
            ->assertJsonPath('counts.processing', 2)
            ->assertJsonPath('counts.queued', 2)
            ->assertJsonCount(1, 'processing')
            ->assertJsonCount(1, 'queue');

        $response->assertJsonFragment(['id' => $processingJob->id]);
        $response->assertJsonFragment(['id' => $queuedJob->id]);

        Carbon::setTestNow();
    }

    public function test_processing_queue_endpoint_returns_queue_details_for_user(): void
    {
        Carbon::setTestNow('2024-04-01 09:00:00');

        $user = User::factory()->create();
        $queuedJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_APPROVED,
            'queued_at' => Carbon::now()->timestamp,
            'frame_count' => 8,
        ]);
        $processingJob = Videojob::factory()->for($user, 'user')->create([
            'status' => Videojob::STATUS_PROCESSING,
            'progress' => 60,
            'queued_at' => Carbon::now()->subMinutes(5)->timestamp,
        ]);

        Videojob::factory()->create(['status' => Videojob::STATUS_APPROVED]);

        $this->actingAs($user, 'api');

        $response = $this->getJson('/api/video-jobs/processing/queue');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['id' => $queuedJob->id]);
        $response->assertJsonFragment(['id' => $processingJob->id]);

        $payload = collect($response->json())->firstWhere('id', $queuedJob->id);
        $this->assertNotEmpty($payload['queue']);
        $this->assertSame(1, $payload['queue']['your_position']);

        Carbon::setTestNow();
    }
}
