<?php

namespace App\Services\VideoJobs;

use App\Models\Videojob;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Asynchronous video processor with real-time progress tracking
 * 
 * Handles video encoding with non-blocking progress updates and file watching
 */
class AsyncVideoProcessor
{
    private FileSystemWatcher $fileWatcher;
    private EncodingProgressParser $progressParser;
    private int $progressUpdateInterval;

    public function __construct(
        ?FileSystemWatcher $fileWatcher = null,
        ?EncodingProgressParser $progressParser = null,
        int $progressUpdateInterval = 5
    ) {
        $this->fileWatcher = $fileWatcher ?? new FileSystemWatcher();
        $this->progressParser = $progressParser ?? new EncodingProgressParser();
        $this->progressUpdateInterval = $progressUpdateInterval;
    }

    /**
     * Process video with async progress tracking
     * 
     * @param Videojob $videoJob The video job to process
     * @param string $command The encoding command to run
     * @param int $timeout Maximum time in seconds
     * @return bool Success status
     */
    public function process(Videojob $videoJob, string $command, int $timeout = 7200): bool
    {
        Log::info("AsyncVideoProcessor: Starting processing", [
            'job_id' => $videoJob->id,
            'command' => $command
        ]);

        $startTime = time();
        $this->progressParser->setTotalFrames($videoJob->frame_count);

        // Start the encoding process
        $process = Process::fromShellCommandline($command);
        $process->setTimeout($timeout);
        $process->start();

        // Start watching for output file
        $outputPath = $videoJob->getFinishedVideoPath();
        $watcherStarted = false;

        try {
            // Monitor the process with progress tracking
            while ($process->isRunning()) {
                // Start file watcher after a brief delay (process needs time to start)
                if (!$watcherStarted && (time() - $startTime) > 10) {
                    $watcherStarted = true;
                }

                // Read output incrementally
                $output = $process->getIncrementalOutput();
                $errorOutput = $process->getIncrementalErrorOutput();

                if (!empty($output) || !empty($errorOutput)) {
                    $this->processOutput($videoJob, $output . $errorOutput, $startTime);
                }

                // Check if output file appeared early (process might finish before we poll)
                if (file_exists($outputPath) && filesize($outputPath) > 0) {
                    Log::info("AsyncVideoProcessor: Output file detected while process running", [
                        'job_id' => $videoJob->id,
                        'file' => $outputPath
                    ]);
                }

                // Brief sleep to avoid busy-waiting
                usleep(100000); // 100ms
            }

            // Process has finished - get final exit code
            $exitCode = $process->getExitCode();

            if ($exitCode !== 0) {
                throw new ProcessFailedException($process);
            }

            // Wait for output file if not yet available
            if (!file_exists($outputPath)) {
                Log::info("AsyncVideoProcessor: Waiting for output file", [
                    'job_id' => $videoJob->id,
                    'expected_path' => $outputPath
                ]);

                $outputPath = $this->fileWatcher->watchForJobCompletion($videoJob, 300); // 5 min timeout
                
                if ($outputPath === null) {
                    throw new \Exception("Output file not created after encoding completed");
                }
            }

            // Final progress update
            $elapsed = time() - $startTime;
            $videoJob->updateProgress($elapsed, 100, 0)->save();

            Log::info("AsyncVideoProcessor: Processing completed successfully", [
                'job_id' => $videoJob->id,
                'duration' => $elapsed,
                'output_file' => $outputPath
            ]);

            return true;

        } catch (ProcessFailedException $exception) {
            Log::error("AsyncVideoProcessor: Process failed", [
                'job_id' => $videoJob->id,
                'error' => $exception->getMessage(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput()
            ]);

            $videoJob->status = 'error';
            $videoJob->save();

            throw $exception;

        } catch (\Exception $exception) {
            Log::error("AsyncVideoProcessor: Unexpected error", [
                'job_id' => $videoJob->id,
                'error' => $exception->getMessage()
            ]);

            if ($process->isRunning()) {
                $process->stop(3, SIGTERM);
            }

            $videoJob->status = 'error';
            $videoJob->save();

            throw $exception;
        }
    }

    /**
     * Process output from encoding and update job progress
     */
    private function processOutput(Videojob $videoJob, string $output, int $startTime): void
    {
        if (empty($output)) {
            return;
        }

        $progress = $this->progressParser->parseOutput($output);

        if (!empty($progress) && isset($progress['progress_percent'])) {
            $progressPercent = $progress['progress_percent'];

            // Only update if progress changed significantly (avoid too many DB writes)
            if ($this->progressParser->hasSignificantChange($progressPercent, 1.0)) {
                $elapsed = time() - $startTime;
                $eta = $this->progressParser->calculateETA($progressPercent, $elapsed);

                $videoJob->updateProgress($elapsed, min(99, $progressPercent), $eta);
                $videoJob->save();

                Log::debug("AsyncVideoProcessor: Progress update", [
                    'job_id' => $videoJob->id,
                    'progress' => $progressPercent,
                    'frame' => $progress['frame'] ?? 'N/A',
                    'eta' => $eta
                ]);
            }
        }
    }

    /**
     * Process video with output file watching (alternative approach)
     * 
     * This is useful when the encoding process doesn't provide progress output
     */
    public function processWithFileWatching(Videojob $videoJob, string $command, int $timeout = 7200): bool
    {
        Log::info("AsyncVideoProcessor: Starting processing with file watching", [
            'job_id' => $videoJob->id
        ]);

        $startTime = time();
        $outputPath = $videoJob->getFinishedVideoPath();

        // Start the encoding process in background (Process::start() is already async)
        $process = Process::fromShellCommandline($command);
        $process->setTimeout($timeout);
        $process->start();

        try {
            // Wait for the output file to be created and completed
            $result = $this->fileWatcher->watchForJobCompletion($videoJob, $timeout);

            if ($result === null) {
                throw new \Exception("Video encoding timeout - no output file created");
            }

            $elapsed = time() - $startTime;
            $videoJob->updateProgress($elapsed, 100, 0)->save();

            Log::info("AsyncVideoProcessor: Processing completed", [
                'job_id' => $videoJob->id,
                'duration' => $elapsed,
                'output_file' => $result
            ]);

            return true;

        } catch (\Exception $exception) {
            Log::error("AsyncVideoProcessor: Error during file watching", [
                'job_id' => $videoJob->id,
                'error' => $exception->getMessage()
            ]);

            $videoJob->status = 'error';
            $videoJob->save();

            throw $exception;
        }
    }
}
