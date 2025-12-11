<?php

namespace Tests\Unit\Services;

use App\Models\Videojob;
use App\Services\VideoJobs\VideoStitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VideoStitcherTest extends TestCase
{
    use RefreshDatabase;

    private VideoStitcher $videoStitcher;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->hasFFmpeg()) {
            $this->markTestSkipped('FFmpeg is not available in the test environment.');
        }

        $this->videoStitcher = new VideoStitcher();
    }

    private function hasFFmpeg(): bool
    {
        exec('which ffmpeg', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Test that stitchVideos creates the output file
     */
    public function test_stitch_videos_creates_output_file()
    {
        $this->markTestSkipped('Requires valid video files for testing');

        $firstVideoPath = '/path/to/test/video1.mp4';
        $secondVideoPath = '/path/to/test/video2.mp4';
        $outputPath = sys_get_temp_dir() . '/test_stitched.mp4';

        // Clean up if file exists
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $result = $this->videoStitcher->stitchVideos($firstVideoPath, $secondVideoPath, $outputPath);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);

        // Clean up
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }
    }

    /**
     * Test that stitchVideos returns false when first video doesn't exist
     */
    public function test_stitch_videos_returns_false_when_first_video_not_found()
    {
        $firstVideoPath = '/non/existent/video1.mp4';
        $secondVideoPath = '/path/to/test/video2.mp4';
        $outputPath = sys_get_temp_dir() . '/test_stitched.mp4';

        $result = $this->videoStitcher->stitchVideos($firstVideoPath, $secondVideoPath, $outputPath);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($outputPath);
    }

    /**
     * Test that stitchVideos returns false when second video doesn't exist
     */
    public function test_stitch_videos_returns_false_when_second_video_not_found()
    {
        $this->markTestSkipped('Requires valid video file for testing');

        $firstVideoPath = '/path/to/test/video1.mp4';
        $secondVideoPath = '/non/existent/video2.mp4';
        $outputPath = sys_get_temp_dir() . '/test_stitched.mp4';

        $result = $this->videoStitcher->stitchVideos($firstVideoPath, $secondVideoPath, $outputPath);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($outputPath);
    }

    /**
     * Test that stitchExtendedJob returns false when base job video doesn't exist
     */
    public function test_stitch_extended_job_returns_false_when_base_video_not_found()
    {
        $baseJob = Videojob::factory()->create([
            'filename' => 'base_video.mp4',
            'outfile' => 'base_output.mp4',
        ]);

        $extendedJob = Videojob::factory()->create([
            'filename' => 'extended_video.mp4',
            'outfile' => 'extended_output.mp4',
        ]);

        $result = $this->videoStitcher->stitchExtendedJob($baseJob, $extendedJob);

        $this->assertFalse($result);
    }

    /**
     * Test that stitchExtendedJob returns false when extended job video doesn't exist
     */
    public function test_stitch_extended_job_returns_false_when_extended_video_not_found()
    {
        $this->markTestSkipped('Requires valid video file for testing');

        $baseJob = Videojob::factory()->create([
            'filename' => 'base_video.mp4',
            'outfile' => 'base_output.mp4',
        ]);

        // Create the base job's finished video file
        $baseVideoPath = $baseJob->getFinishedVideoPath();
        File::ensureDirectoryExists(dirname($baseVideoPath));
        touch($baseVideoPath);

        $extendedJob = Videojob::factory()->create([
            'filename' => 'extended_video.mp4',
            'outfile' => 'extended_output.mp4',
        ]);

        $result = $this->videoStitcher->stitchExtendedJob($baseJob, $extendedJob);

        $this->assertFalse($result);

        // Clean up
        if (file_exists($baseVideoPath)) {
            unlink($baseVideoPath);
        }
    }

    /**
     * Test that stitchExtendedJob replaces extended video with stitched version
     */
    public function test_stitch_extended_job_replaces_extended_video()
    {
        $this->markTestSkipped('Requires valid video files for testing');

        $baseJob = Videojob::factory()->create([
            'filename' => 'base_video.mp4',
            'outfile' => 'base_output.mp4',
        ]);

        $extendedJob = Videojob::factory()->create([
            'filename' => 'extended_video.mp4',
            'outfile' => 'extended_output.mp4',
        ]);

        // Create dummy video files
        $baseVideoPath = $baseJob->getFinishedVideoPath();
        $extendedVideoPath = $extendedJob->getFinishedVideoPath();

        File::ensureDirectoryExists(dirname($baseVideoPath));
        File::ensureDirectoryExists(dirname($extendedVideoPath));

        // Copy test videos or create dummy files
        touch($baseVideoPath);
        touch($extendedVideoPath);

        $result = $this->videoStitcher->stitchExtendedJob($baseJob, $extendedJob);

        $this->assertTrue($result);
        $this->assertFileExists($extendedVideoPath);

        // Clean up
        if (file_exists($baseVideoPath)) {
            unlink($baseVideoPath);
        }
        if (file_exists($extendedVideoPath)) {
            unlink($extendedVideoPath);
        }
    }
}
