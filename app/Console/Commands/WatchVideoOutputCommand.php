<?php

namespace App\Console\Commands;

use App\Services\VideoJobs\FileSystemWatcher;
use App\Models\Videojob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WatchVideoOutputCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video:watch-output 
                            {--path=* : Additional paths to watch}
                            {--interval=5 : Polling interval in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Watch video output directories for completed encodings';

    private FileSystemWatcher $watcher;
    private bool $shouldStop = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting video output watcher...');

        $interval = (int)$this->option('interval');
        $this->watcher = new FileSystemWatcher($interval);

        // Watch default processed video path
        $processedPath = config('app.paths.processed');
        if ($processedPath && is_dir($processedPath)) {
            $this->watcher->watchPath($processedPath);
            $this->info("Watching: {$processedPath}");
        }

        // Watch additional paths from options
        foreach ($this->option('path') as $path) {
            if (is_dir($path)) {
                $this->watcher->watchPath($path);
                $this->info("Watching: {$path}");
            } else {
                $this->warn("Path not found: {$path}");
            }
        }

        // Set up signal handlers for graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
            pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        }

        $this->info('Watcher started. Press Ctrl+C to stop.');
        $this->info("Polling interval: {$interval} seconds");

        try {
            $this->watcher->start(
                onNewFile: function (string $file) {
                    $this->handleNewFile($file);
                },
                onModifiedFile: function (string $file) {
                    $this->handleModifiedFile($file);
                }
            );
        } catch (\Exception $e) {
            $this->error("Watcher error: {$e->getMessage()}");
            Log::error("Video output watcher error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        $this->info('Watcher stopped.');
        return 0;
    }

    /**
     * Handle new file detection
     */
    private function handleNewFile(string $file): void
    {
        $this->info("New file detected: {$file}");

        // Try to find the corresponding video job
        $videoJob = $this->findJobByOutputFile($file);

        if ($videoJob) {
            $this->info("  → Associated with job #{$videoJob->id}");

            // Update job status if it's still processing
            if (in_array($videoJob->status, ['processing', 'approved'])) {
                $this->info("  → Updating job status to finished");
                
                $videoJob->status = 'finished';
                $videoJob->progress = 100;
                $videoJob->estimated_time_left = 0;
                $videoJob->save();

                Log::info("Video output watcher: Job completed", [
                    'job_id' => $videoJob->id,
                    'file' => $file
                ]);
            }
        } else {
            $this->warn("  → No matching job found");
        }
    }

    /**
     * Handle modified file detection
     */
    private function handleModifiedFile(string $file): void
    {
        $this->line("File modified: {$file}");

        // Could implement progress estimation based on file size growth
        $videoJob = $this->findJobByOutputFile($file);

        if ($videoJob && $videoJob->status === 'processing') {
            $this->line("  → Job #{$videoJob->id} still processing...");
        }
    }

    /**
     * Find video job by output file path
     */
    private function findJobByOutputFile(string $file): ?Videojob
    {
        $basename = basename($file);

        // Try to find job by exact filename match
        $job = Videojob::where('outfile', $basename)->first();

        if (!$job) {
            // Try to match by pattern (filename might have suffixes)
            $filenameWithoutExt = pathinfo($basename, PATHINFO_FILENAME);
            $job = Videojob::where('outfile', 'like', $filenameWithoutExt . '%')
                ->orderBy('created_at', 'desc')
                ->first();
        }

        return $job;
    }

    /**
     * Handle shutdown signals
     */
    public function handleShutdown(): void
    {
        $this->info("\nReceived shutdown signal. Stopping watcher...");
        $this->watcher->stop();
    }
}
