<?php

namespace App\Services\VideoJobs;

use App\Models\Videojob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class VideoStitcher
{
    /**
     * Stitch two videos together
     *
     * @param string $firstVideoPath Path to the first video
     * @param string $secondVideoPath Path to the second video
     * @param string $outputPath Path where the stitched video should be saved
     * @return bool Success status
     */
    public function stitchVideos(string $firstVideoPath, string $secondVideoPath, string $outputPath): bool
    {
        if (!file_exists($firstVideoPath)) {
            Log::warning('First video file not found for stitching', [
                'video_path' => $firstVideoPath,
            ]);
            return false;
        }

        if (!file_exists($secondVideoPath)) {
            Log::warning('Second video file not found for stitching', [
                'video_path' => $secondVideoPath,
            ]);
            return false;
        }

        try {
            // Ensure output directory exists
            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                File::makeDirectory($outputDir, 0755, true);
            }

            // Create a temporary file list for ffmpeg concat
            $listPath = sys_get_temp_dir() . '/ffmpeg_concat_' . uniqid() . '.txt';
            
            // Write absolute paths to the list file (no need for escaping in file)
            $listContent = sprintf(
                "file '%s'\nfile '%s'",
                $firstVideoPath,
                $secondVideoPath
            );
            
            file_put_contents($listPath, $listContent);

            Log::info('Stitching videos together', [
                'first_video' => $firstVideoPath,
                'second_video' => $secondVideoPath,
                'output' => $outputPath,
                'list_file' => $listPath,
            ]);

            // Use ffmpeg concat demuxer to stitch videos
            $process = new Process([
                'ffmpeg',
                '-y',
                '-f', 'concat',
                '-safe', '0',
                '-i', $listPath,
                '-c', 'copy',
                $outputPath
            ]);
            $process->setTimeout(600); // 10 minutes for large videos
            $process->mustRun();

            // Clean up temporary list file
            if (file_exists($listPath)) {
                unlink($listPath);
            }

            if (file_exists($outputPath)) {
                Log::info('Videos stitched successfully', [
                    'output_path' => $outputPath,
                    'output_size' => filesize($outputPath),
                ]);
                return true;
            }

            return false;
        } catch (ProcessFailedException $exception) {
            Log::error('Failed to stitch videos', [
                'first_video' => $firstVideoPath,
                'second_video' => $secondVideoPath,
                'output' => $outputPath,
                'error' => $exception->getMessage(),
            ]);

            // Clean up temporary list file on error
            if (isset($listPath) && file_exists($listPath)) {
                unlink($listPath);
            }

            return false;
        }
    }

    /**
     * Stitch an extended job's video with its base job's video
     *
     * @param Videojob $baseJob The original job
     * @param Videojob $extendedJob The extended job
     * @return bool Success status
     */
    public function stitchExtendedJob(Videojob $baseJob, Videojob $extendedJob): bool
    {
        $baseVideoPath = $baseJob->getFinishedVideoPath();
        $extendedVideoPath = $extendedJob->getFinishedVideoPath();

        if (!file_exists($baseVideoPath)) {
            Log::warning('Base job video not found for stitching', [
                'base_job_id' => $baseJob->id,
                'video_path' => $baseVideoPath,
            ]);
            return false;
        }

        if (!file_exists($extendedVideoPath)) {
            Log::warning('Extended job video not found for stitching', [
                'extended_job_id' => $extendedJob->id,
                'video_path' => $extendedVideoPath,
            ]);
            return false;
        }

        // Create a temporary output path for the stitched video
        $tempOutputPath = $extendedVideoPath . '.stitched.mp4';

        $success = $this->stitchVideos($baseVideoPath, $extendedVideoPath, $tempOutputPath);

        if ($success && file_exists($tempOutputPath)) {
            // Replace the extended job's video with the stitched version
            if (file_exists($extendedVideoPath)) {
                unlink($extendedVideoPath);
            }
            rename($tempOutputPath, $extendedVideoPath);

            Log::info('Extended job video replaced with stitched version', [
                'base_job_id' => $baseJob->id,
                'extended_job_id' => $extendedJob->id,
                'output_path' => $extendedVideoPath,
            ]);

            return true;
        }

        return false;
    }
}
