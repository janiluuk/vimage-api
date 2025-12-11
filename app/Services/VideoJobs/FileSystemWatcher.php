<?php

namespace App\Services\VideoJobs;

use App\Models\Videojob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * File system watcher service for monitoring video encoding output
 * 
 * This service watches directories for newly created or modified video files
 * and automatically processes them when they become available.
 */
class FileSystemWatcher
{
    private array $watchedPaths = [];
    private array $knownFiles = [];
    private int $pollInterval;
    private bool $running = false;

    public function __construct(int $pollInterval = 5)
    {
        $this->pollInterval = $pollInterval;
    }

    /**
     * Add a directory to watch
     */
    public function watchPath(string $path): self
    {
        if (!is_dir($path)) {
            throw new \InvalidArgumentException("Path does not exist or is not a directory: {$path}");
        }

        if (!in_array($path, $this->watchedPaths)) {
            $this->watchedPaths[] = $path;
            $this->knownFiles[$path] = $this->scanDirectory($path);
            Log::info("FileSystemWatcher: Started watching path", ['path' => $path]);
        }

        return $this;
    }

    /**
     * Start watching for file changes
     */
    public function start(callable $onNewFile, callable $onModifiedFile = null): void
    {
        $this->running = true;

        Log::info("FileSystemWatcher: Started monitoring", [
            'paths' => $this->watchedPaths,
            'poll_interval' => $this->pollInterval
        ]);

        while ($this->running) {
            foreach ($this->watchedPaths as $path) {
                $this->checkForChanges($path, $onNewFile, $onModifiedFile);
            }

            sleep($this->pollInterval);
        }

        Log::info("FileSystemWatcher: Stopped monitoring");
    }

    /**
     * Stop watching
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Check a directory for new or modified files
     */
    private function checkForChanges(string $path, callable $onNewFile, ?callable $onModifiedFile): void
    {
        $currentFiles = $this->scanDirectory($path);
        $knownFiles = $this->knownFiles[$path] ?? [];

        // Check for new files
        foreach ($currentFiles as $file => $mtime) {
            if (!isset($knownFiles[$file])) {
                // New file detected
                if ($this->isFileComplete($file)) {
                    Log::info("FileSystemWatcher: New file detected", ['file' => $file]);
                    call_user_func($onNewFile, $file);
                    $this->knownFiles[$path][$file] = $mtime;
                }
            } elseif ($mtime > $knownFiles[$file]) {
                // File modified
                if ($onModifiedFile && $this->isFileComplete($file)) {
                    Log::info("FileSystemWatcher: File modified", ['file' => $file]);
                    call_user_func($onModifiedFile, $file);
                    $this->knownFiles[$path][$file] = $mtime;
                }
            }
        }

        // Update known files
        $this->knownFiles[$path] = $currentFiles;
    }

    /**
     * Scan a directory and return files with their modification times
     */
    private function scanDirectory(string $path): array
    {
        $files = [];
        $pattern = $path . '/*.{mp4,webm,mov,avi}';

        foreach (glob($pattern, GLOB_BRACE) as $file) {
            if (is_file($file)) {
                $files[$file] = filemtime($file);
            }
        }

        return $files;
    }

    /**
     * Check if a file is completely written (not still being written)
     * 
     * This prevents processing files that are still being encoded
     */
    private function isFileComplete(string $file): bool
    {
        if (!file_exists($file)) {
            return false;
        }

        // Check if file size is stable (not growing)
        $size1 = filesize($file);
        usleep(100000); // 100ms delay instead of 1 second for better performance
        clearstatcache(true, $file);
        $size2 = filesize($file);

        return $size1 === $size2 && $size1 > 0;
    }

    /**
     * Watch for specific video job completion
     */
    public function watchForJobCompletion(Videojob $videoJob, int $timeout = 7200): ?string
    {
        $startTime = time();
        $expectedPath = $videoJob->getFinishedVideoPath();
        $directory = dirname($expectedPath);
        $filename = basename($expectedPath);

        Log::info("FileSystemWatcher: Watching for job completion", [
            'job_id' => $videoJob->id,
            'expected_path' => $expectedPath,
            'timeout' => $timeout
        ]);

        while ((time() - $startTime) < $timeout) {
            // Check if the expected file exists
            if (file_exists($expectedPath) && $this->isFileComplete($expectedPath)) {
                Log::info("FileSystemWatcher: Job output file detected", [
                    'job_id' => $videoJob->id,
                    'file' => $expectedPath,
                    'elapsed_time' => time() - $startTime
                ]);
                return $expectedPath;
            }

            // Also check for alternative encodings or temporary files
            $pattern = dirname($expectedPath) . '/' . pathinfo($filename, PATHINFO_FILENAME) . '*.' . pathinfo($filename, PATHINFO_EXTENSION);
            foreach (glob($pattern) as $file) {
                if ($this->isFileComplete($file)) {
                    Log::info("FileSystemWatcher: Alternative job output detected", [
                        'job_id' => $videoJob->id,
                        'file' => $file,
                        'elapsed_time' => time() - $startTime
                    ]);
                    return $file;
                }
            }

            sleep($this->pollInterval);
        }

        Log::warning("FileSystemWatcher: Timeout waiting for job completion", [
            'job_id' => $videoJob->id,
            'timeout' => $timeout
        ]);

        return null;
    }

    /**
     * Get list of watched paths
     */
    public function getWatchedPaths(): array
    {
        return $this->watchedPaths;
    }

    /**
     * Check if watching is active
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
