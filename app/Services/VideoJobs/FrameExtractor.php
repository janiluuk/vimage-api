<?php

namespace App\Services\VideoJobs;

use App\Models\Videojob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class FrameExtractor
{
    /**
     * Extract the first frame from a video
     *
     * @param string $videoPath Path to the video file
     * @param string $outputPath Path where the frame should be saved
     * @return bool Success status
     */
    public function extractFirstFrame(string $videoPath, string $outputPath): bool
    {
        if (!file_exists($videoPath)) {
            Log::warning('Video file not found for first frame extraction', [
                'video_path' => $videoPath,
            ]);
            return false;
        }

        try {
            // Ensure output directory exists
            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                File::makeDirectory($outputDir, 0755, true);
            }

            // Extract first frame using ffmpeg
            $process = new Process([
                'ffmpeg',
                '-y',
                '-i', $videoPath,
                '-vframes', '1',
                $outputPath
            ]);
            $process->setTimeout(30);
            $process->mustRun();

            if (file_exists($outputPath)) {
                Log::info('First frame extracted successfully', [
                    'video_path' => $videoPath,
                    'output_path' => $outputPath,
                ]);
                return true;
            }

            return false;
        } catch (ProcessFailedException $exception) {
            Log::error('Failed to extract first frame', [
                'video_path' => $videoPath,
                'output_path' => $outputPath,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Extract the last frame from a video
     *
     * @param string $videoPath Path to the video file
     * @param string $outputPath Path where the frame should be saved
     * @return bool Success status
     */
    public function extractLastFrame(string $videoPath, string $outputPath): bool
    {
        if (!file_exists($videoPath)) {
            Log::warning('Video file not found for last frame extraction', [
                'video_path' => $videoPath,
            ]);
            return false;
        }

        try {
            // Ensure output directory exists
            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                File::makeDirectory($outputDir, 0755, true);
            }

            // Extract last frame using ffmpeg (seek to end minus 1 second)
            $process = new Process([
                'ffmpeg',
                '-y',
                '-sseof', '-1',
                '-i', $videoPath,
                '-vframes', '1',
                $outputPath
            ]);
            $process->setTimeout(30);
            $process->mustRun();

            if (file_exists($outputPath)) {
                Log::info('Last frame extracted successfully', [
                    'video_path' => $videoPath,
                    'output_path' => $outputPath,
                ]);
                return true;
            }

            return false;
        } catch (ProcessFailedException $exception) {
            Log::error('Failed to extract last frame', [
                'video_path' => $videoPath,
                'output_path' => $outputPath,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Extract and save first and last frames for a video job
     *
     * @param Videojob $videoJob The video job to extract frames from
     * @param string $videoPath Path to the video file
     * @return bool Success status
     */
    public function extractAndSaveFrames(Videojob $videoJob, string $videoPath): bool
    {
        $firstFramePath = $videoJob->getFirstFramePath();
        $lastFramePath = $videoJob->getLastFramePath();

        $firstFrameSuccess = $this->extractFirstFrame($videoPath, $firstFramePath);
        $lastFrameSuccess = $this->extractLastFrame($videoPath, $lastFramePath);

        if ($firstFrameSuccess) {
            $videoJob->first_frame_path = $firstFramePath;
        }

        if ($lastFrameSuccess) {
            $videoJob->last_frame_path = $lastFramePath;
        }

        if ($firstFrameSuccess || $lastFrameSuccess) {
            $videoJob->save();
        }

        return $firstFrameSuccess && $lastFrameSuccess;
    }
}
