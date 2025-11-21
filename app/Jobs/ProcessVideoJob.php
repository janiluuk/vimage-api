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
    public function handle(VideoProcessingService $service)
    {
        $start_time = time();

        Videojob::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'error']);


        $processingJobs = Videojob::where('status', VideoJob::STATUS_PROCESSING)->count();

        if ($this->previewFrames == 0 && $processingJobs > 0) {
            if ($this->videoJob && $this->videoJob->status == VideoJob::STATUS_PROCESSING) {
                $this->videoJob->status = VideoJob::STATUS_APPROVED;
                $this->videoJob->save();
            }
            Log::info("Found existing process, aborting..");

            return;
        }
        if ($this->videoJob) {
            $videoJob = $this->videoJob;
            try {
                $pids = false;
                Log::info("Starting...");

                exec('ps aux | grep -i video2video | grep -i \"\-\-jobid=' . $videoJob->id . '\" | grep -v grep', $pids);
                if (!empty($pids) && $videoJob->status == Videojob::STATUS_PROCESSING && $this->previewFrames == 0) {
                    $videoJob->status = VideoJob::STATUS_APPROVED;
                    $videoJob->save();
                    Log::info("Found existing process, aborting..");
                    return;
                }

                $videoJob->resetProgress(Videojob::STATUS_PROCESSING);
                $videoJob->job_time = time() - $start_time;
                if ($videoJob->frame_count > 0) {
                    $videoJob->estimated_time_left = ($videoJob->frame_count * 10) + 5;
                    $videoJob->save();
                }
                $targetFile = implode("/", [config('app.paths.processed'), $videoJob->outfile]);
                $targetUrl = config('app.url') . '/processed/' . $videoJob->outfile;

                Log::info("Starting " . ($this->previewFrames ? " frames PREVIEW " : "") . "conversion for {$videoJob->filename} to {$targetFile} URL: ($targetUrl} ");

                $service->startProcess($videoJob, $this->previewFrames);


                Log::info('WTF converted {url} in {duration}', ['url' => $videoJob->url, 'duration' => $videoJob->job_time]);

            } catch (\Exception $e) {
                Log::info('Error while converting a video job: {error} ', ['error' => $e->getMessage(), 'retries' => $videoJob->retries]);

                $videoJob->job_time = time() - $start_time;
                $this->videoJob = $videoJob;
                $this->videoJob->queued_at = \Carbon\Carbon::now();
                $this->videoJob->retries+=1;
                $this->videoJob->save();
                throw $e;
            }

        }
    }
    public function retryUntil(): DateTimeInterface
    {
       return now()->addDay();
    }

}