<?php

namespace App\Services;

use App\Models\ModelFile;
use App\Models\Videojob;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DeforumProcessingService
{
    public function parseJob(Videojob $videoJob, string $path)
    {

        if (strstr($videoJob->mimetype, 'image') !== false) {
            try {
                list($width, $height, $type, $attr) = getimagesize($path);


                $videoJob->size = filesize($path);
                $videoJob->width = $width;
                $videoJob->height = $height;
                $scaled = $this->getScaledSize($videoJob);
                $videoJob->width = $scaled[0];
                $videoJob->height = $scaled[1];

                return $videoJob;
            } catch (\Exception $e) {
                Log::error($e->getMessage());
                throw $e;
            }
        }
    }
    public function getScaledSize(Videojob $videoJob)
    {
        $max_dimension = 960;
        $width = $videoJob->width;
        $height = $videoJob->height;

        if ($width == $height && $width >= $max_dimension) {
            return [960, 960];

        } elseif ($width > $height) {
            while ($width > $max_dimension) {
                $height = ($height * $max_dimension) / $width;
                $width = $max_dimension;
            }
        } elseif ($width < $height) {
            while ($height > $max_dimension) {
                $width = ($width * $max_dimension) / $height;
                $height = $max_dimension;
            }
        }

        return [$width, $height];
    }


    public function startProcess(Videojob $videoJob, $previewFrames = 0, ?int $extendFromJobId = null)
    {
        $isPreview = $previewFrames > 0;

        try {
            $videoJob = $this->parseJob($videoJob, $videoJob->getOriginalVideoPath());
            
            $videoJob->save();
            $cmd = $this->buildCommandLine(
                $videoJob,
                $videoJob->getOriginalVideoPath(),
                $videoJob->getFinishedVideoPath(),
                $previewFrames,
                $extendFromJobId
            );
            $this->killProcess($videoJob->id);
            Log::info("Deforum Conversion {$videoJob->id}: Running {$cmd}");
            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(27200);
            try {
                $time = time();
                $output = $process->mustRun();
                // Parse the JSON output
                $decoded_output = json_decode($output->getOutput(), true);
                // Get the first job ID
                if (empty($decoded_output['job_ids'])) {
                     throw new \Exception("invalid response, " . $output->getOutput());
                }

                $first_job_id = $decoded_output['job_ids'][0];
                $videoJob->job_id = $first_job_id;
                $videoJob->save();
                $running = true;
                $client = new \GuzzleHttp\Client();
                $execution_times = [];
                $progresses = [];   
                while ($running) {

                    // Using GuzzleHttp\Client to make an API request
                    $response = $client->request('GET', 'http://192.168.2.100:7860/deforum_api/jobs/' . $first_job_id);
                    $data = json_decode($response->getBody(), true);
                

                    Log::info("Got response: {$response->getBody()}", ['data' => $data]);

                    $videoJob = Videojob::findOrFail($videoJob->id);
                    
                    if ($videoJob->status == 'cancelled' || $videoJob->status == 'error') {
                        $response = $client->request('DELETE', 'http://192.168.2.100:7860/deforum_api/jobs/' . $first_job_id);
                        Log::info("Deleted job {$first_job_id}: {$response->getBody()}");
                        $running = false;
                        
                    }
                    // Initialize arrays to hold last n values of execution_time and phase_progress
                   
                    // Update database
                    if ($data['phase'] == 'GENERATING') {
                            // Update arrays with latest values
                    
                        array_push($execution_times, $data['execution_time']);
                        array_push($progresses, $data['phase_progress']*100);

                        // Keep only the last n values
                        $n = 5; // You can choose a different value for n
                        if (count($execution_times) > $n) {
                            array_shift($execution_times);
                        }
                        if (count($progresses) > $n) {
                            array_shift($progresses);
                        }

                        // Calculate moving averages
                        $avg_execution_time = array_sum($execution_times) / count($execution_times);
                        $avg_progress = array_sum($progresses) / count($progresses);

                        // Calculate estimated time left using moving averages
                        if ($avg_progress > 0) {
                            $videoJob->estimated_time_left = round((($avg_execution_time / $avg_progress) * 100) - $data['execution_time']);
                        }
                        
                        $videoJob->progress = $data['phase_progress'] * 100;
                        $videoJob->job_time = $data['execution_time'];
          
                    }
                    
                    if ($data['phase'] === 'QUEUED' && $videoJob->status !== "approved") {
                        $videoJob->status = 'approved';
                    } elseif ($data['phase'] == 'GENERATING' && $videoJob->status != "processing") {
                        $videoJob->status = 'processing';
                    }

                    if ($data['status'] === 'SUCCEEDED' && $data['phase'] == 'DONE') {
                        $sourceFile = implode("/", [$data['outdir'],  $data['timestring'] . '.mp4']);
                        $sourceAnimation = implode("/", [$data['outdir'],  $data['timestring'] . '.gif']);
                        $previewPic = implode("/", [$data['outdir'],  $data['timestring'] . '_000000005.png']);

                        if (is_file($sourceFile) && $previewFrames == 0 ) {
                            $videoJob->outfile = basename($sourceFile);
                            $targetUrl = config('app.url') . '/processed/' . $videoJob->outfile;
                            $videoJob->url = $targetUrl;
                            rename($sourceFile, $videoJob->getFinishedVideoPath());
                        }
                        if (is_file($sourceAnimation)) {
                            $videoJob->preview_animation = $sourceAnimation;
                            rename($sourceAnimation, $videoJob->getPreviewAnimationPath());
                        }
                        if (is_file($previewPic)) {
                            $videoJob->preview_img = $previewPic;
                            rename($previewPic, $videoJob->getPreviewImagePath());
                        }


                        if ($previewFrames == 0 && ! empty($videoJob->soundtrack_path)) {
                            $this->mergeSoundtrack($videoJob);
                        }

                        $videoJob->save();
                        $running = false;

                    } elseif ($data['status'] !== 'ACCEPTED' && $data['status'] !== 'SUCCEEDED') {
                        $videoJob->status = 'error';
                        $videoJob->save();
                        $running = false;
                        
                        throw new \Exception("Error in job: " . json_encode($data));

                    }

                    $videoJob->save();
                    if ($running) sleep(5);
                }

                $videoJob->attachResults('deforum');
                $videoJob->save();
                $elapsed = time() - $time;
                $videoJob->updateProgress($elapsed, 99, 7)->save();
                $videoJob->refresh();

                if ($videoJob->frame_count == 0)
                    $videoJob->frame_count++;

                Log::info("Finished in {" . (time() - $time) . "} seconds :  {$videoJob->frame_count} frames on " . round($videoJob->frame_count / $elapsed) . "  frames/s speed. {output} ", ['output' => $process->getOutput()]);


                $videoJob->refresh();

                Log::info("Paths: ", ['preview' => $videoJob->getMediaFilesForRevision('image'), 'animation' => $videoJob->getMediaFilesForRevision('animation'), 'finished_video' => $videoJob->getMediaFilesForRevision('video', 'finished')]);

                //$videoJob->verifyAndCleanPreviews();
                $videoJob->status = ($isPreview) ? 'preview' : 'finished';

                $videoJob->updateProgress(time() - $time, 100, 0)->save();

            } catch (ProcessFailedException $exception) {
                Log::info('Error while making ' . ($isPreview ? "preview" : "final") . ' conversion for ' . $videoJob->filename, ['exception' => $exception->getMessage()]);
                $videoJob->status = "error";
                $videoJob->save();

                throw $exception;
            }
        } catch (\Exception $e) {
            Log::info("Error while processing video {$videoJob->filename}: {$e->getMessage()} ", ['error' => $e->getMessage(), 'videoFile' => $videoJob->filename]);
            $videoJob->resetProgress('error');
            $videoJob->save();
            throw $e;

        }
    }

    private function buildCommandLine(Videojob $videoJob, $sourceFile, $outFile, $previewFrames = 0, ?int $extendFromJobId = null)
    {
        if ($previewFrames < 5 && $previewFrames > 0 ) $previewFrames = 5;

        $modelFile = ModelFile::find($videoJob->model_id);
        $file = explode(" [", $modelFile->filename);
        $modelFilename = $file[0];
        $prompts = $this->applyPrompts($videoJob);

        $cmdString = '';
        $jsonSettings = [];

        $initImg = $this->resolveInitImage($videoJob, $extendFromJobId);

        $params = [
            'modelFile' => $modelFile->filename,
            'init_img' => $initImg,
            'json_settings_file' => '/www/api/scripts/zoom.json',
        ];

        $jsonSettings['prompts'] = '{ "0": "' . addslashes($prompts[0]) .  ' --neg ' . addslashes($prompts[1]) . '" }';
        $jsonSettings['checkpoint_schedule'] = '"0: (\"' . $modelFilename . '\"), 100: (\"' . $modelFilename . '\")"';
        $jsonSettings['max_frames'] =  $previewFrames > 0 ? $previewFrames : (int)$videoJob->frame_count;
        $jsonSettings['sd_model_hash'] = isset($file[1]) ? '"' . str_replace("]", "", $file[1]) . '"' : '""';
        $jsonSettings['sd_model_name'] = '"' .trim($file[0]) . '"';
        $jsonSettings['positive_prompts'] = '"' . addslashes($prompts[0]) . '"';
        $jsonSettings['negative_prompts'] = '"' . addslashes($prompts[1]) . '"';
        $jsonSettings['W'] = $videoJob->width > 0 ? $videoJob->width : 540;
        $jsonSettings['H'] = $videoJob->height > 0 ? $videoJob->height : 960;


        $normalizedSettings = [
            'prompts' => [
                'positive' => $prompts[0],
                'negative' => $prompts[1],
            ],
            'checkpoint_schedule' => $modelFilename,
            'max_frames' => $jsonSettings['max_frames'],
            'sd_model_hash' => isset($file[1]) ? str_replace("]", "", $file[1]) : '',
            'sd_model_name' => trim($file[0]),
            'dimensions' => [
                'width' => $videoJob->width > 0 ? $videoJob->width : 540,
                'height' => $videoJob->height > 0 ? $videoJob->height : 960,
            ],
        ];

        $videoJob->generation_parameters = json_encode([
            'model_id' => $videoJob->model_id,
            'prompts' => $normalizedSettings['prompts'],
            'frame_count' => $jsonSettings['max_frames'],
            'sd_model_hash' => $normalizedSettings['sd_model_hash'],
            'sd_model_name' => $normalizedSettings['sd_model_name'],
            'dimensions' => $normalizedSettings['dimensions'],
            'seed' => $videoJob->seed,
            'denoising' => $videoJob->denoising,
            'fps' => $videoJob->fps,
            'length' => $videoJob->length,
            'extend_from_job' => $extendFromJobId,
            'init_img' => $initImg,
            'json_settings' => $normalizedSettings,
        ]);
        $videoJob->revision = md5($videoJob->generation_parameters);

        //$jsonSettings['skip_video_creation'] = $previewFrames > 0 ? 'true' : 'false';

        $json_param = '{';
        $comma = '';
        foreach ($jsonSettings as $key  => $val) {
            $json_param .= $comma . ' "' . $key . '": ' . $val;
            $comma = ',';
        }
        $json_param .="}";

        $videoJob->save();

        foreach ($params as $key => $val) {
            if ($key == 'modelFile') {
                $cmdString .= sprintf("--%s='%s' ", $key, $val);
            } else {
                $cmdString .= sprintf('--%s=%s ', $key, $val);
            }
        }
        $cmdString .= ' --json_settings=\''. $json_param . '\' ';
        $processor = config('app.paths.deforum_processor_path');

        $cmdParts = [
            $processor,
            $cmdString,
            '--start'
        ];

        return implode(' ', $cmdParts);
    }

    private function mergeSoundtrack(Videojob $videoJob): void
    {
        $soundtrackPath = $videoJob->soundtrack_path;
        $finishedVideoPath = $videoJob->getFinishedVideoPath();

        if (empty($soundtrackPath) || ! file_exists($soundtrackPath) || ! file_exists($finishedVideoPath)) {
            Log::warning('Skipping soundtrack merge because audio or video file is missing', [
                'video_job_id' => $videoJob->id,
                'soundtrack' => $soundtrackPath,
                'video' => $finishedVideoPath,
            ]);

            return;
        }

        $targetFile = preg_replace('/\.mp4$/', '_soundtrack.mp4', $finishedVideoPath);

        $command = sprintf(
            'ffmpeg -y -i %s -i %s -c:v copy -c:a aac -map 0:v:0 -map 1:a:0 -shortest %s',
            escapeshellarg($finishedVideoPath),
            escapeshellarg($soundtrackPath),
            escapeshellarg($targetFile)
        );

        $process = Process::fromShellCommandline($command);

        try {
            $process->mustRun();
            rename($targetFile, $finishedVideoPath);
            $videoJob->audio_codec = 'aac';
        } catch (ProcessFailedException $exception) {
            Log::warning('Unable to apply soundtrack to rendered video', [
                'video_job_id' => $videoJob->id,
                'error' => $exception->getMessage(),
            ]);

            if (file_exists($targetFile)) {
                @unlink($targetFile);
            }
        }
    }

    private function resolveInitImage(Videojob $videoJob, ?int $extendFromJobId): string
    {
        if ($extendFromJobId === null) {
            return $videoJob->getOriginalVideoPath();
        }

        $sourceJob = Videojob::find($extendFromJobId);

        if (! $sourceJob) {
            return $videoJob->getOriginalVideoPath();
        }

        $sourcePath = $sourceJob->hasFinishedVideo() ? $sourceJob->getFinishedVideoPath() : $sourceJob->getOriginalVideoPath();

        if (! file_exists($sourcePath)) {
            return $videoJob->getOriginalVideoPath();
        }

        $targetDir = dirname($videoJob->getOriginalVideoPath());
        $initFramePath = sprintf('%s/%s_extend_init.png', $targetDir, $videoJob->id);

        try {
            $command = sprintf(
                'ffmpeg -y -sseof -1 -i %s -vframes 1 %s',
                escapeshellarg($sourcePath),
                escapeshellarg($initFramePath)
            );

            $process = Process::fromShellCommandline($command);
            $process->mustRun();

            return $initFramePath;
        } catch (ProcessFailedException $exception) {
            Log::warning('Failed to extract last frame for init image, falling back to original', [
                'video_job_id' => $videoJob->id,
                'source_job' => $extendFromJobId,
                'error' => $exception->getMessage(),
            ]);

            return $videoJob->getOriginalVideoPath();
        }
    }
    public function applyPrompts(Videojob $videoJob)
    {

        $promptSuffix = config('app.processing.default_prompt_suffix');
        $negPromptSuffix = config('app.processing.default_negative_prompt_suffix');

        $prompt = sprintf('%s, %s', str_replace('"', "", trim($videoJob->prompt)), $promptSuffix);

        if (empty($videoJob->negative_prompt)) {
            $negativePrompt = $negPromptSuffix;
        } else {
            $negativePrompt = sprintf("%s, %s", str_replace('"', "", trim($videoJob->negative_prompt)), $negPromptSuffix);
        }
        Log::info("Resolved prompts as " . $prompt . " && " . $negativePrompt);

        return [$prompt, $negativePrompt];
    }
    public function cancelJob(Videojob $videoJob)
    {

        $this->killProcess($videoJob->id);

        if ($videoJob->status == Videojob::STATUS_PROCESSING || $videoJob->status == Videojob::STATUS_APPROVED) {
            $videoJob->resetProgress('cancelled');
            $videoJob->save();
        }
    }
    /**
     * Kill existing process
     *
     * @param int $id
     * @return void
     */
    public function killProcess($sessionId)
    {

        try {
            $pids = false;

            exec('ps aux | grep -i deforum.py | grep -i \"\-\-jobid=' . $sessionId . '\" | grep -v grep', $pids);

            if (empty($pids) || count($pids) < 1) {
                return;
            } else {

                Log::info("Killing process {$sessionId}", ['pids' => $pids]);
                $command = sprintf("kill -9 %s", $pids[0]);
                $process = \Illuminate\Support\Facades\Process::run($command);
                Log::info($process->output());
            }
        } catch (ProcessFailedException $exception) {
            throw new \Exception($exception->getMessage());
        }
    }
}