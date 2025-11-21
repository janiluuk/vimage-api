<?php

namespace Tests\Unit;

use App\Jobs\ProcessDeforumJob;
use App\Jobs\ProcessVideoJob;
use App\Models\Videojob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessJobsUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_job_is_unique_per_video_and_preview(): void
    {
        $videoJob = Videojob::factory()->make(['id' => 101]);

        $job = new ProcessVideoJob($videoJob, 0);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('101-0', $job->uniqueId());
        $this->assertSame(3600, $job->uniqueFor);
    }

    public function test_deforum_job_is_unique_per_video_and_preview(): void
    {
        $videoJob = Videojob::factory()->make(['id' => 202]);

        $job = new ProcessDeforumJob($videoJob, 5);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('202-5', $job->uniqueId());
        $this->assertSame(3600, $job->uniqueFor);
    }
}
