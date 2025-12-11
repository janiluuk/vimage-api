<?php

namespace App\Services;

use App\Models\ModelFile;
use App\Models\Videojob;
use FFMpeg\FFProbe;
use FFMpeg\Format\Video\X264;
use FFMpeg\FFMpeg as FFMpegOg;
use FFMpeg\FFProbe\DataMapping\Stream;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class VideoProcessingService
{
    private $ffmpeg;
    private $ffprobe;

    public function __construct(?FFMpegOg $ffmpeg = null, ?FFProbe $ffprobe = null)
    {
        /* @var \FFMpeg\FFMpeg FFMpegOg */
        $this->ffmpeg = $ffmpeg ?? FFMpegOg::create(
            [
                'ffmpeg.binaries' => '/usr/bin/ffmpeg',
                // Path to the FFMpeg binary
                'ffprobe.binaries' => '/usr/bin/ffprobe',
                // Path to the FFProbe binary
                'timeout' => 3600,
                'ffmpeg.threads' => 12,
            ]
        );

        $this->ffprobe = $ffprobe ?? FFProbe::create(
            [
                'ffmpeg.binaries' => '/usr/bin/ffmpeg',
                // Path to the FFMpeg binary
                'ffprobe.binaries' => '/usr/bin/ffprobe',
                // Path to the FFProbe binary
                'timeout' => 3600,
            ]
        );
    }

    public function cropVideo($path, $duration, $width = 0, $height = 0)
    {
        $outputPath = str_replace(".mp4", "_cropped.mp4", $path);

        $video = FFMpeg::open($path);

        $video->filters();

        Log::info("Cropping {$path} and clipping 10 seconds");

        $video->filters()
            ->clip(TimeCode::fromSeconds(0), TimeCode::fromSeconds($duration));
        if ($width > 0 && $height > 0) {
            Log::info("Resizing {$path} to {$width}x{$height}");
            $video->crop(new Dimension($width, $height));
        }
        $video->save(new X264('aac'), $outputPath);

        return $outputPath;
    }

    public function extractVideoInfo($path)
    {
        try {
            $ffmpeg = $this->ffmpeg;

            $video = $ffmpeg->open($path);

            $format = $video->getFormat();
            $streams = $video->getStreams();

            $duration = $format->get('duration');
            $frameRate = $streams->first(
                function (Stream $stream) {
                    return $stream->isVideo();
                }
            )->get('r_frame_rate');

            if ($frameRate < 1) {
                $format = new X264();
                $format->setAudioCodec("aac");
                $format->setAdditionalParameters(explode(' ', '-pix_fmt yuv420p -b:v 4000k'));
                $video->save($format, $path);
                $video = $ffmpeg->open($path);

            }
            $videoStream = $streams->first(
                function (Stream $stream) {
                    return $stream->isVideo();
                }
            );

            $audioStreams = $streams->audios();

            $audioCodec = NULL;

            if ($audioStreams->count() > 0) {
                $audioCodec = $audioStreams->first()->get('codec_name');
            }
            $dimension = new Dimension($videoStream->get('width', 640), $videoStream->get('height', 480));
            $width = $dimension->getWidth();
            $height = $dimension->getHeight();

            $codec = $videoStream->get('codec_name');
            $bitrate = $videoStream->get('bit_rate');
            $fps = $this->parseFrameRate($frameRate);
            $size = filesize($path);

            $framesCount = $format->get('nb_frames');

            if (empty($framesCount) && !empty($duration) && !empty($fps)) {
                $framesCount = $fps * $duration;
            }

            $videoInfo = [
                'duration' => $duration,
                'fps' => $fps,
                'width' => $width,
                'height' => $height,
                'length' => $duration,
                'codec' => $codec,
                'bitrate' => $bitrate,
                'audio_codec' => $audioCodec,
                'frame_count' => $framesCount,
                'size' => $size,
            ];
            Log::info("Parsed info from the video {$path}:", ['info' => $videoInfo]);

        } catch (\Exception $e) {
            Log::error('Unable to parse file:' . $e->getMessage());
            throw new \Exception("Unable to parse videofile:" . $e->getMessage());
        }
        Log::info('Resolved video {$path}:' . json_encode($videoInfo));
        return $videoInfo;
    }
    public function parseJob(Videojob $videoJob, string $path)
    {

        try {
            $videoInfo = $this->extractVideoInfo($path);

            $videoJob->fps = $videoInfo['fps'];
            $videoJob->codec = $videoInfo['codec'];
            $videoJob->frame_count = $videoInfo['frame_count'];
            $videoJob->size = $videoInfo['size'];
            $videoJob->width = $videoInfo['width'];
            $videoJob->bitrate = $videoInfo['bitrate'];
            $videoJob->audio_codec = $videoInfo['audio_codec'];
            $videoJob->length = $videoInfo['duration'];
            $videoJob->height = $videoInfo['height'];
            $scaled = $this->getScaledSize($videoJob);
            $videoJob->width = $scaled[0];
            $videoJob->height = $scaled[1];

            return $videoJob;
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            throw $e;
        }
    }
    public function getScaledSize(Videojob $videoJob)
    {
        $max_dimension = 960;
        $width = $videoJob->width;
        $height = $videoJob->height;

        if ($width == $height && $width >= $max_dimension) {
            return [500, 500];
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
                $process->mustRun();
                $videoJob->refresh();

                $elapsed = time() - $time;
                $videoJob->updateProgress($elapsed, 99, 7)->save();

                if ($videoJob->frame_count == 0)
                    $videoJob->frame_count++;

                if (! $isPreview && ! empty($videoJob->soundtrack_path)) {
                    $this->mergeSoundtrack($videoJob);
                }

                Log::info("Finished in {" . (time() - $time) . "} seconds :  {$videoJob->frame_count} frames on " . round($videoJob->frame_count / $elapsed) . "  frames/s speed. {output} ", ['output' => $process->getOutput()]);

                $videoJob->attachResults();
                $videoJob->save();
                $videoJob->refresh();

                Log::info("Paths: ", ['preview' => $videoJob->getMediaFilesForRevision('image'), 'animation' => $videoJob->getMediaFilesForRevision('animation'), 'finished_video' => $videoJob->getMediaFilesForRevision('video', 'finished')]);
                
                //$videoJob->verifyAndCleanPreviews();
                $videoJob->status = ($isPreview) ? 'preview' : 'finished';

                $videoJob->updateProgress(time() - $time, 100, 0)->save();

            } catch (ProcessFailedException $exception) {
                Log::info('Error while making ' . ($isPreview ? "preview" : "finfal") . ' conversion for ' . $videoJob->filename, ['exception' => $exception->getMessage()]);
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
    private function buildPreviewParameters(Videojob $videoJob, $previewFrames = 0): array
    {
        $params = [];
        if ($previewFrames > 0) {
            $filename_ext = pathinfo($videoJob->outfile, PATHINFO_EXTENSION);
            $previewFile = preg_replace('/^(.*)\.' . $filename_ext . '$/', '$1_preview.' . 'png', $videoJob->outfile);
            $previewPath = sprintf('%s', rtrim(config('app.paths.preview'), '/'));
            $animationFile = preg_replace('/^(.*)\.' . $filename_ext . '$/', '$1_animated_preview.' . 'png', $videoJob->outfile);

            $previewUrl = config('app.paths.preview_public');
            $params['preview_url'] = $previewUrl;
            $params['preview_img'] = $previewFrames >= 1 ? sprintf("%s/%s", $previewPath, basename($previewFile)) : '';
            $params['preview_animation'] = $previewFrames > 1 ? sprintf("%s/%s", $previewPath, basename($animationFile)) : '';
            $params['limit_frames_amount'] = $previewFrames;

            Log::info(sprintf("Setting paths for preview_img, preview_url, preview_animation to path: %s / %s / %s / %s", $params['preview_img'], $previewUrl, $params['preview_animation'], $previewPath));
        }
        return $params;
    }

    private function buildCommandLine(Videojob $videoJob, $sourceFile, $outFile, $previewFrames = 0)
    {

        $modelFile = ModelFile::find($videoJob->model_id);
        $prompts = $this->applyPrompts($videoJob);
        $cmdString = '';

        $params = [
            'width' => $videoJob->width,
            'height' => $videoJob->height,
            'cfg_scale' => $videoJob->cfg_scale,
            'steps' => $videoJob->steps,
            'denoising_strength' => $videoJob->denoising,
            'prompt' =>  $prompts[0] ,
            'negative_prompt' => $prompts[1],
            'seed' => $videoJob->seed,
            'jobid' => $videoJob->id,
            'fps' => (int) $videoJob->fps,
            'model' => '"' . $modelFile->filename . '"',
            'outfile' => $outFile
        ];

        if (! empty($videoJob->soundtrack_path)) {
            $params['soundtrack'] = '"' . $videoJob->soundtrack_path . '"';
        }
        

        if (!empty($videoJob->controlnet)) {
            $controlnetArgs = $this->generateControlnetParams((array) json_decode($videoJob->controlnet, true));
            if (is_array($controlnetArgs))
                $params += $controlnetArgs;
        }
        $newParams =  json_encode($params);
        if ($videoJob->generation_parameters != $newParams) {
            Log::info('Making new version since generation params dont match:', [$newParams, $videoJob->generation_params]);
            $cmdString .= sprintf('--overwrite ');
        }

        $videoJob->generation_parameters = $newParams;
        $videoJob->revision = md5($videoJob->generation_parameters);
        $videoJob->save();

        $params += $this->buildPreviewParameters($videoJob, $previewFrames);


        foreach ($params as $key => $val) {
            if ($key == 'prompt' || $key == 'negative_prompt') {
                $cmdString .= sprintf("--%s=\"%s\" ", $key, $val);
            } else {
                $cmdString .= sprintf('--%s=%s ', $key, $val);
            }
        }
        
        $processor = config('app.paths.image_processor_path');

        $cmdParts = [
            $processor,
            $sourceFile,
            $cmdString,
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
            $pids = [];

            $search = sprintf('--jobid=%s', $sessionId);
            exec(sprintf('ps -eo pid,command | grep -i video2video | grep -F "%s" | grep -v grep', $search), $pids);

            if (empty($pids)) {
                return;
            }

            foreach ($pids as $rawProcess) {
                [$pid] = preg_split('/\s+/', trim($rawProcess), 2);

                if (! is_numeric($pid)) {
                    continue;
                }

                Log::info("Killing process {$sessionId}", ['pid' => $pid]);
                $process = new Process(['kill', '-9', (int) $pid]);
                $process->mustRun();
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

    private function parseFrameRate($frameRate): float
    {
        if (is_numeric($frameRate)) {
            return (float) $frameRate;
        }

        if (is_string($frameRate) && str_contains($frameRate, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $frameRate, 2), 2, 0);

            if ($denominator > 0) {
                return (float) $numerator / (float) $denominator;
            }
        }

        return 0.0;
    }
}