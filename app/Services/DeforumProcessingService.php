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
            return [500, 500];
        } else if ($width > $height) {
            while ($width > $max_dimension) {
                $height = ($height * $max_dimension) / $width;
                $width = $max_dimension;
            }
        } else if ($width < $height) {
            while ($height > $max_dimension) {
                $width = ($width * $max_dimension) / $height;
                $height = $max_dimension;
            }
        }

        return [$width, $height];
    }

        
    public function startProcess(Videojob $videoJob, $previewFrames = 0)
    {
        $isPreview = $previewFrames > 0;

        try {
            
            $cmd = $this->buildCommandLine($videoJob, $videoJob->getOriginalVideoPath(), $videoJob->getFinishedVideoPath(), $previewFrames);
            $this->killProcess($videoJob->id);
            Log::info("Conversion {$videoJob->id}: Running {$cmd}");
            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(7200);
            try {
                $time = time();
                $output = $process->mustRun();
                // Parse the JSON output
                $decoded_output = json_decode($output->getOutput(), true);
                // Get the first job ID
                $first_job_id = $decoded_output['job_ids'][0];

                $running = true;
                $client = new \GuzzleHttp\Client();

                while ($running) {
                // Using GuzzleHttp\Client to make an API request
                    $response = $client->request('GET', 'http://192.168.2.100:7860/deforum_api/jobs/'.$first_job_id);
                    $data = json_decode($response->getBody(), true);
                    Log::info("Got response: {$response->getBody()}", ['data' => $data]);

                    // Update database
                    $videoJob->progress = $data['phase_progress'] * 100;
                    $videoJob->job_time = $data['execution_time'];
                    $videoJob->outfile = $data['outdir'].$data['timestring'].'.mp4';
                    if ($data['phase'] ==='QUEUED' && $videoJob->status !== "approved") {
                        $videoJob->status='approved';
                        $videoJob->save();
                    }
                    if ($data['status'] === 'DONE') {
                        $videoJob->save();
                        $running = false;

                    } elseif ($data['status'] !== 'ACCEPTED') {
                        $videoJob->status = 'error';
                        $videoJob->save();
                        $running = false;

                        throw new \Exception("Error in job: " . json_encode($data));

                    }
                    sleep(5);
                }

                $videoJob->refresh();

                $elapsed = time() - $time;
                $videoJob->updateProgress($elapsed, 99, 7)->save();

                if ($videoJob->frame_count == 0)
                    $videoJob->frame_count++;

                Log::info("Finished in {" . (time() - $time) . "} seconds :  {$videoJob->frame_count} frames on " . round($videoJob->frame_count / $elapsed) . "  frames/s speed. {output} ", ['output' => $process->getOutput()]);
                
                if (is_file($videoJob->outfile)) {
                    rename($videoJob->outfile, $videoJob->getFinishedVideoPath());
                }

                $videoJob->attachResults();
                $videoJob->save();
                $videoJob->refresh();

                Log::info("Paths: ", ['preview' => $videoJob->getMediaFilesForRevision('image'), 'animation' => $videoJob->getMediaFilesForRevision('animation'), 'finished_video' => $videoJob->getMediaFilesForRevision('video', 'finished')]);

                //$videoJob->verifyAndCleanPreviews();
                $videoJob->status = ($isPreview) ? 'preview' : 'finished';

                $videoJob->updateProgress(time() - $time, 100, 0)->save();

            } catch (ProcessFailedException $exception) {
                Log::info('Error while making ' . ($isPreview ? "preview" : "final") . ' conversion for ' . $videoJob->filename, ['exception' => $exception->getMessage()]);
                $videoJob->status = "error";
                $videoJob->save();

                throw new \Exception($exception->getMessage());
            }
        } catch (\Exception $e) {

            Log::info("Error while processing video {$videoJob->filename}: {$e->getMessage()} ", ['error' => $e->getMessage(), 'videoFile' => $videoJob->filename]);
            $videoJob->resetProgress('error');
            $videoJob->save();
            throw new \Exception($e->getMessage());
        }
    }
    private function buildPreviewParameters(VideoJob $videoJob, $previewFrames = 0): array
    {
        $params = [];
        if ($previewFrames > 0) {
            $filename_ext = pathinfo($videoJob->outfile, PATHINFO_EXTENSION);
            $previewFile = preg_replace('/^(.*)\.' . $filename_ext . '$/', '$1_preview.' . 'png', $videoJob->outfile);
            $previewPath = sprintf('%s', rtrim(config('app.paths.preview'), '/'));
            $animationFile = preg_replace('/^(.*)\.' . $filename_ext . '$/', '$1_animated_preview.' . 'png', $videoJob->outfile);

            $params['preview_img'] = $previewFrames >= 1 ? sprintf("%s/%s", $previewPath, basename($previewFile)) : '';
            $params['preview_animation'] = $previewFrames > 1 ? sprintf("%s/%s", $previewPath, basename($animationFile)) : '';
            $params['limit_frames_amount'] = $previewFrames;

            Log::info(sprintf("Setting paths for preview_img, preview_animation to path: %s / %s / %s", $params['preview_img'], $params['preview_animation'], $previewPath));
        }
        return $params;
    }

    private function buildCommandLine(VideoJob $videoJob, $sourceFile, $outFile, $previewFrames = 0)
    {

        $modelFile = ModelFile::find($videoJob->model_id);
        $cmdString = '';

        $params = [
            'modelFile' => $modelFile->filename,
            'init_img' => $videoJob->getOriginalVideoPath(),
            'json_settings_file' => '/www/api/scripts/zoom.json',
        ];


        $videoJob->generation_parameters = json_encode($params);
        $videoJob->revision = md5($videoJob->generation_parameters);

        $videoJob->save();

       // $params += $this->buildPreviewParameters($videoJob, $previewFrames);


        foreach ($params as $key => $val) {
            if ($key == 'modelFile' || $key == 'negative_prompt') {
                $cmdString .= sprintf("--%s=\"%s\" ", $key, $val);
            } else
                $cmdString .= sprintf('--%s=%s ', $key, $val);
        }

        $processor = config('app.paths.deforum_processor_path');

        $cmdParts = [
            $processor,
            $cmdString,
            '--start'
        ];

        return implode(' ', $cmdParts);
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

            exec('ps aux | grep -i video2video | grep -i \"\-\-jobid=' . $sessionId . '\" | grep -v grep', $pids);

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

    public function generateControlnetParams(array $data)
    {
        $argStrings = [];
        $controlnetUnits = [];
        $i = 0;
        foreach ((array) $data as $id => $unit) {
            $i++;
            $paramName = sprintf("unit%s_params", $i);

            foreach ($unit as $key => $param) {
                $paramValue = $param;
                if (is_bool($param))
                    $paramValue = $param ? 'True' : 'False';
                $controlnetUnits[$paramName][] = sprintf("%s=%s", $key, $paramValue);
            }
        }

        foreach ($controlnetUnits as $unitName => $values) {
            $argStrings[$unitName] = "'" . implode(', ', $values) . "'";
        }

        return $argStrings;
    }
}