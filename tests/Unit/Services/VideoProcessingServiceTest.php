<?php

namespace Tests\Unit\Services;

use App\Services\VideoProcessingService;
use PHPUnit\Framework\TestCase;

class VideoProcessingServiceTest extends TestCase
{
    private $videoProcessingService;

    protected function setUp(): void
    {
        parent::setUp();

        self::markTestSkipped('FFmpeg binaries are not available in the test environment.');

        // Instantiate the VideoProcessingService
        $this->videoProcessingService = new VideoProcessingService();
    }

    public function testExtractVideoInfo()
    {
        $filePath = "/tmp/video.mp4";
        // Call the method under test
        $videoInfo = $this->videoProcessingService->extractVideoInfo($filePath);

        // Assert that the returned array has the expected keys
        $this->assertArrayHasKey('duration', $videoInfo);
        $this->assertArrayHasKey('frame_rate', $videoInfo);
        $this->assertArrayHasKey('width', $videoInfo);
        $this->assertArrayHasKey('height', $videoInfo);
        $this->assertArrayHasKey('codec', $videoInfo);
        $this->assertArrayHasKey('bitrate', $videoInfo);
        $this->assertArrayHasKey('frames_count', $videoInfo);

        // Assert that the returned values are of the expected types
        $this->assertIsInt($videoInfo['duration']);
        $this->assertIsString($videoInfo['frame_rate']);
        $this->assertIsInt($videoInfo['width']);
        $this->assertIsInt($videoInfo['height']);
        $this->assertIsString($videoInfo['codec']);
        $this->assertIsString($videoInfo['bitrate']);
        $this->assertIsInt($videoInfo['frames_count']);
    }

    public function testCropVideo()
    {
        $filePath = '/tmp/video.mp4';
        

        //'//to/video.mp4';
        $duration = 10;

        // Call the method under test
        $croppedPath = $this->videoProcessingService->cropVideo($filePath, $duration);

        // Assert that the croppedPath is a non-empty string
        $this->assertIsString($croppedPath);
        $this->assertNotEmpty($croppedPath);
    }

    public function testStoreVideoFile()
    {
        // Create a mock file object
        $file = $this->getMockBuilder(\Illuminate\Http\UploadedFile::class)
            ->disableOriginalConstructor()
            ->getMock();

        $path = 'videos/video.mp4';

        // Call the method under test
        $result = $this->videoProcessingService->storeVideoFile($file, $path);

        // Assert that the result is true
        $this->assertTrue($result);
    }

    public function testGetScaledSize()
    {
        $width = 1280;
        $height = 720;

        // Call the method under test
        $scaledSize = $this->videoProcessingService->getScaledSize($width, $height);

        // Assert that the returned array has two elements
        $this->assertCount(2, $scaledSize);

        // Assert that the scaledSize values are integers
        $this->assertIsInt($scaledSize[0]);
        $this->assertIsInt($scaledSize[1]);
    }
}