<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Videojob;
use App\Services\DeforumProcessingService;
use Illuminate\Support\Facades\Log;

set_time_limit(27200);

class ProcessDeforumJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 27200;
    public $tries = 200;
    public $backoff = 30; // delay in seconds between retries
    public $uniqueFor = 3600;
    const MAX_RETRIES = 5;

    public function __construct(public Videojob $videoJob, public int $previewFrames = 0)
    {

    }

    public function uniqueId(): string
    {
        return $this->videoJob->id . '-' . $this->previewFrames;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(DeforumProcessingService $service)
    {
        $start_time = time();

        Videojob::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'error']);


        $processingJobs = Videojob::where('status', VideoJob::STATUS_PROCESSING)->count();
        $deforumJobs = Videojob::where('status', VideoJob::STATUS_PROCESSING)->where('generator', 'deforum')->count();

        if ($deforumJobs > 0 && $this->previewFrames == 0 && (!$this->videoJob || $processingJobs > 0)) {
            if ($this->videoJob && $this->videoJob->status == VideoJob::STATUS_PROCESSING) {
                $this->videoJob->status = VideoJob::STATUS_APPROVED;
                $this->videoJob->save();
            }
            if ($this->videoJob->generator != 'deforum') {
                $this->fail("not a deforum job (".$this->videoJob->generator.")");
            }
            Log::info("Found existing process, aborting..");
            return;
        }
        if ($this->videoJob) {
            $videoJob = $this->videoJob;
            try {
                $pids = false;
                Log::info("Starting deforum job for #" . $videoJob->id);

                exec('ps aux | grep -i deforum.py | grep -i \"\-\-jobid=' . $videoJob->id . '\" | grep -v grep', $pids);
                if (!empty($pids) && $videoJob->status == Videojob::STATUS_PROCESSING) {
                    $videoJob->status = VideoJob::STATUS_APPROVED;
                    $videoJob->save();
                    Log::info("Found existing process, aborting..");
                    return;
                }

                $videoJob->resetProgress(Videojob::STATUS_PROCESSING);
                $videoJob->job_time = time()-$start_time;
                if ($videoJob->frame_count > 0) {
                    $videoJob->estimated_time_left = $videoJob->frame_count * 6;
                    $videoJob->save();
                }
                $targetFile = implode("/", [config('app.paths.processed'), $videoJob->outfile]);
                $targetUrl = config('app.url') . '/processed/' . $videoJob->outfile;
                
                Log::info("Starting " . ($this->previewFrames ? " PREVIEW " : "") . "conversion for {$videoJob->filename} to {$targetFile} URL: ($targetUrl} ");
                
                $service->startProcess($videoJob, $this->previewFrames);

                if (file_exists($targetFile) && $this->previewFrames == 0) {

                    $videoJob->job_time = time() - $start_time;
                    $videoJob->progress = 100;
                    $videoJob->estimated_time_left = 0;
                    $videoJob->url = $targetUrl;
                    $videoJob->status = 'finished';
                    $videoJob->save();
                    Log::info('Successfully converted {url} in {duration}', ['url' => $videoJob->url, 'duration' => $videoJob->job_time]);
                }

            } catch (\Exception $e) {
                Log::error('Error while converting a video job: {error} ', ['error' => $e->getMessage()]);
                $videoJob->job_time = time() - $start_time;
                $videoJob->status = 'error';
                $videoJob->save();
                $this->fail($e->getMessage());
            }
        }
    }
    
}