<?php

namespace App\Jobs;

use App\Models\ModelFile;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Videojob;
use App\Services\VideoProcessingService;
use Illuminate\Support\Facades\Log;

set_time_limit(27200);

class ProcessVideoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 27200;
    public $tries = 200;
    public $backoff = 30; // delay in seconds between retries
    public $uniqueFor = 3600;
    const MAX_RETRIES = 5;

    public function __construct(public Videojob $videoJob, public int $previewFrames = 0, public ?int $extendFromJobId = null)
    {

    }

    public function uniqueId(): string
    {
        $id = $this->videoJob->id . '-' . $this->previewFrames;
        if ($this->extendFromJobId !== null) {
            $id .= '-' . $this->extendFromJobId;
        }
        return $id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(VideoProcessingService $service)
    {
        $start_time = time();

        Videojob::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'error']);

        // Check concurrent job limit
        $maxConcurrentJobs = config('app.video_processing.max_concurrent_jobs', 1);
        $processingJobs = Videojob::where('status', VideoJob::STATUS_PROCESSING)->count();

        if ($this->previewFrames == 0 && $maxConcurrentJobs > 0 && $processingJobs >= $maxConcurrentJobs) {
            if ($this->videoJob && $this->videoJob->status == VideoJob::STATUS_PROCESSING) {
                $this->videoJob->status = VideoJob::STATUS_APPROVED;
                $this->videoJob->save();
            }
            Log::info("Maximum concurrent jobs reached, requeueing", [
                'current_jobs' => $processingJobs,
                'max_allowed' => $maxConcurrentJobs
            ]);

            // Release with shorter delay for better responsiveness
            $this->release(10);
            return;
        }
        if ($this->videoJob) {
            $videoJob = $this->videoJob;
            try {
                Log::info("Starting video job processing", ['job_id' => $videoJob->id]);

                // Check for existing processing using cache instead of exec for better performance and security
                $lockKey = $this->getProcessingLockKey($videoJob->id);
                $isLocked = \Cache::has($lockKey);
                
                if ($isLocked && $videoJob->status == Videojob::STATUS_PROCESSING && $this->previewFrames == 0) {
                    $videoJob->status = VideoJob::STATUS_APPROVED;
                    $videoJob->save();
                    Log::info("Job is already being processed, aborting", ['job_id' => $videoJob->id]);
                    return;
                }

                // Set lock for 30 minutes
                \Cache::put($lockKey, true, now()->addMinutes(30));

                $videoJob->resetProgress(Videojob::STATUS_PROCESSING);
                $videoJob->job_time = time() - $start_time;
                if ($videoJob->frame_count > 0) {
                    $videoJob->estimated_time_left = ($videoJob->frame_count * 10) + 5;
                    $videoJob->save();
                }
                $targetFile = implode("/", [config('app.paths.processed'), $videoJob->outfile]);
                $targetUrl = config('app.url') . '/processed/' . $videoJob->outfile;

                Log::info("Starting conversion", [
                    'job_id' => $videoJob->id,
                    'preview_frames' => $this->previewFrames,
                    'target_file' => $targetFile,
                    'extend_from_job_id' => $this->extendFromJobId,
                ]);

                $service->startProcess($videoJob, $this->previewFrames, $this->extendFromJobId);

                // Release lock on successful completion
                \Cache::forget($this->getProcessingLockKey($videoJob->id));

                Log::info('Video conversion completed', [
                    'job_id' => $videoJob->id,
                    'url' => $videoJob->url,
                    'duration' => $videoJob->job_time
                ]);

            } catch (\Exception $e) {
                // Release lock on error
                \Cache::forget($this->getProcessingLockKey($videoJob->id));
                
                Log::error('Error while converting video job', [
                    'job_id' => $videoJob->id,
                    'error' => $e->getMessage(),
                    'retries' => $videoJob->retries
                ]);

                $videoJob->job_time = time() - $start_time;
                $this->videoJob = $videoJob;
                $this->videoJob->queued_at = \Carbon\Carbon::now();
                $this->videoJob->retries += 1;
                $this->videoJob->save();
                throw $e;
            }
        }
    }

    /**
     * Get the cache key for processing lock
     */
    private function getProcessingLockKey(int $jobId): string
    {
        return "video_job_processing_{$jobId}";
    }

    public function retryUntil(): DateTimeInterface
    {
       return now()->addDay();
    }

}