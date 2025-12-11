<?php

namespace Tests\Unit\Services;

use App\Models\Videojob;
use App\Services\VideoJobs\FrameExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrameExtractorTest extends TestCase
{
    use RefreshDatabase;

    private FrameExtractor $frameExtractor;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->hasFFmpeg()) {
            $this->markTestSkipped('FFmpeg is not available in the test environment.');
        }

        $this->frameExtractor = new FrameExtractor();
    }

    protected function tearDown(): void
    {
        // Clean up any test files created
        $framesPath = config('app.paths.frames', public_path('frames'));
        if (is_dir($framesPath)) {
            File::cleanDirectory($framesPath);
        }

        parent::tearDown();
    }

    private function hasFFmpeg(): bool
    {
        exec('which ffmpeg', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Test that extractFirstFrame creates the output file
     */
    public function test_extract_first_frame_creates_output_file()
    {
        $this->markTestSkipped('Requires valid video file for testing');

        $videoPath = '/path/to/test/video.mp4';
        $outputPath = sys_get_temp_dir() . '/test_first_frame.png';

        // Clean up if file exists
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $result = $this->frameExtractor->extractFirstFrame($videoPath, $outputPath);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);

        // Clean up
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }
    }

    /**
     * Test that extractLastFrame creates the output file
     */
    public function test_extract_last_frame_creates_output_file()
    {
        $this->markTestSkipped('Requires valid video file for testing');

        $videoPath = '/path/to/test/video.mp4';
        $outputPath = sys_get_temp_dir() . '/test_last_frame.png';

        // Clean up if file exists
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $result = $this->frameExtractor->extractLastFrame($videoPath, $outputPath);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);

        // Clean up
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }
    }

    /**
     * Test that extractFirstFrame returns false when video file doesn't exist
     */
    public function test_extract_first_frame_returns_false_when_video_not_found()
    {
        $videoPath = '/non/existent/video.mp4';
        $outputPath = sys_get_temp_dir() . '/test_first_frame.png';

        $result = $this->frameExtractor->extractFirstFrame($videoPath, $outputPath);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($outputPath);
    }

    /**
     * Test that extractLastFrame returns false when video file doesn't exist
     */
    public function test_extract_last_frame_returns_false_when_video_not_found()
    {
        $videoPath = '/non/existent/video.mp4';
        $outputPath = sys_get_temp_dir() . '/test_last_frame.png';

        $result = $this->frameExtractor->extractLastFrame($videoPath, $outputPath);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($outputPath);
    }

    /**
     * Test that extractAndSaveFrames updates the video job with frame paths
     */
    public function test_extract_and_save_frames_updates_video_job()
    {
        $this->markTestSkipped('Requires valid video file for testing');

        // Create a test video job
        $videoJob = Videojob::factory()->create([
            'filename' => 'test_video.mp4',
            'outfile' => 'test_output.mp4',
        ]);

        $videoPath = '/path/to/test/video.mp4';

        $result = $this->frameExtractor->extractAndSaveFrames($videoJob, $videoPath);

        // Refresh the model from the database
        $videoJob->refresh();

        $this->assertTrue($result);
        $this->assertNotNull($videoJob->first_frame_path);
        $this->assertNotNull($videoJob->last_frame_path);
    }
}
