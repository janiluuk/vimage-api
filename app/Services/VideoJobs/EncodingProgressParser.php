<?php

namespace App\Services\VideoJobs;

use Illuminate\Support\Facades\Log;

/**
 * Parser for extracting encoding progress from video processing output
 * 
 * Supports parsing progress from FFmpeg, Python scripts, and other encoders
 */
class EncodingProgressParser
{
    private int $totalFrames;
    private int $currentFrame = 0;
    private float $lastProgress = 0.0;

    public function __construct(int $totalFrames = 0)
    {
        $this->totalFrames = $totalFrames;
    }

    /**
     * Parse a line of output and extract progress information
     * 
     * @param string $line Output line from encoding process
     * @return array|null Progress data [frame, progress_percent, fps, time_remaining] or null
     */
    public function parseLine(string $line): ?array
    {
        // FFmpeg progress pattern: "frame= 1234 fps= 25 ..."
        if (preg_match('/frame=\s*(\d+)/', $line, $matches)) {
            $frame = (int)$matches[1];
            $this->currentFrame = $frame;

            $progress = [
                'frame' => $frame,
                'progress_percent' => $this->calculateProgress($frame),
            ];

            // Extract FPS if available
            if (preg_match('/fps=\s*([\d.]+)/', $line, $fpsMatches)) {
                $progress['fps'] = (float)$fpsMatches[1];
            }

            // Extract time if available
            if (preg_match('/time=\s*([\d:.]+)/', $line, $timeMatches)) {
                $progress['time'] = $timeMatches[1];
            }

            // Calculate ETA
            if (isset($progress['fps']) && $progress['fps'] > 0 && $this->totalFrames > 0) {
                $remainingFrames = $this->totalFrames - $frame;
                $progress['eta_seconds'] = (int)($remainingFrames / $progress['fps']);
            }

            return $progress;
        }

        // Python script progress pattern: "Processing frame 1234/5000 (24.68%)"
        if (preg_match('/Processing frame (\d+)\/(\d+)\s*\(?([\d.]+)%?\)?/i', $line, $matches)) {
            $frame = (int)$matches[1];
            $total = (int)$matches[2];
            $percent = (float)$matches[3];

            $this->currentFrame = $frame;
            if ($total > 0) {
                $this->totalFrames = $total;
            }

            return [
                'frame' => $frame,
                'total_frames' => $total,
                'progress_percent' => $percent,
            ];
        }

        // Alternative progress pattern: "Progress: 45.5%"
        if (preg_match('/Progress:\s*([\d.]+)%/i', $line, $matches)) {
            $percent = (float)$matches[1];
            
            return [
                'progress_percent' => $percent,
                'frame' => $this->calculateFrameFromPercent($percent),
            ];
        }

        // Deforum/SD pattern: "Step 50/100"
        if (preg_match('/Step\s+(\d+)\/(\d+)/i', $line, $matches)) {
            $step = (int)$matches[1];
            $totalSteps = (int)$matches[2];
            $percent = ($step / $totalSteps) * 100;

            return [
                'step' => $step,
                'total_steps' => $totalSteps,
                'progress_percent' => $percent,
            ];
        }

        return null;
    }

    /**
     * Parse multiple lines of output at once
     * 
     * @param string $output Complete or partial output
     * @return array Latest progress data
     */
    public function parseOutput(string $output): array
    {
        $lines = explode("\n", $output);
        $latestProgress = null;

        foreach ($lines as $line) {
            $progress = $this->parseLine($line);
            if ($progress !== null) {
                $latestProgress = $progress;
            }
        }

        return $latestProgress ?? [
            'progress_percent' => 0,
            'frame' => 0,
        ];
    }

    /**
     * Calculate progress percentage from current frame
     */
    private function calculateProgress(int $frame): float
    {
        if ($this->totalFrames <= 0 || $frame <= 0) {
            return 0.0;
        }

        $progress = ($frame / $this->totalFrames) * 100;
        return min(99.9, $progress); // Never report 100% until actually complete
    }

    /**
     * Calculate frame number from progress percentage
     */
    private function calculateFrameFromPercent(float $percent): int
    {
        if ($this->totalFrames <= 0) {
            return 0;
        }

        return (int)(($percent / 100) * $this->totalFrames);
    }

    /**
     * Set total frames for progress calculation
     */
    public function setTotalFrames(int $totalFrames): self
    {
        $this->totalFrames = $totalFrames;
        return $this;
    }

    /**
     * Get current frame number
     */
    public function getCurrentFrame(): int
    {
        return $this->currentFrame;
    }

    /**
     * Get total frames
     */
    public function getTotalFrames(): int
    {
        return $this->totalFrames;
    }

    /**
     * Calculate estimated time remaining
     * 
     * @param float $currentProgress Current progress (0-100)
     * @param int $elapsedSeconds Time elapsed so far
     * @return int Estimated seconds remaining
     */
    public function calculateETA(float $currentProgress, int $elapsedSeconds): int
    {
        if ($currentProgress <= 0) {
            return 0;
        }

        // Estimate total time based on current progress
        $estimatedTotal = ($elapsedSeconds / $currentProgress) * 100;
        $remaining = $estimatedTotal - $elapsedSeconds;

        return max(0, (int)$remaining);
    }

    /**
     * Check if progress has significantly changed (for throttling updates)
     * 
     * @param float $currentProgress Current progress percentage
     * @param float $threshold Minimum change threshold
     * @return bool True if progress changed significantly
     */
    public function hasSignificantChange(float $currentProgress, float $threshold = 1.0): bool
    {
        if (abs($currentProgress - $this->lastProgress) >= $threshold) {
            $this->lastProgress = $currentProgress;
            return true;
        }

        return false;
    }
}
