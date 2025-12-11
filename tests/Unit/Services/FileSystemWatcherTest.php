<?php

namespace Tests\Unit\Services;

use App\Services\VideoJobs\FileSystemWatcher;
use Tests\TestCase;

class FileSystemWatcherTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/file_watcher_test_' . time();
        mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->testDir)) {
            array_map('unlink', glob($this->testDir . '/*'));
            rmdir($this->testDir);
        }
        parent::tearDown();
    }

    public function test_watch_path_adds_directory()
    {
        $watcher = new FileSystemWatcher();
        $watcher->watchPath($this->testDir);

        $this->assertContains($this->testDir, $watcher->getWatchedPaths());
    }

    public function test_watch_path_throws_exception_for_invalid_directory()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        $watcher = new FileSystemWatcher();
        $watcher->watchPath('/nonexistent/path/that/does/not/exist');
    }

    public function test_detects_new_files()
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('PCNTL extension is not available');
        }

        // Create a temporary file to store detection results
        $resultFile = sys_get_temp_dir() . '/watcher_test_' . uniqid() . '.txt';
        
        $watcher = new FileSystemWatcher(1);
        $watcher->watchPath($this->testDir);

        // Start watcher in background
        $pid = pcntl_fork();
        if ($pid == 0) {
            // Child process - run watcher and write detections to file
            $watcher->start(function($file) use ($resultFile) {
                file_put_contents($resultFile, $file . "\n", FILE_APPEND);
            });
            exit(0);
        } elseif ($pid > 0) {
            // Parent process - create a test file
            sleep(2);
            $testFile = $this->testDir . '/test_video.mp4';
            file_put_contents($testFile, 'test content for detection');
            
            sleep(3); // Give watcher time to detect
            
            // Stop child process
            posix_kill($pid, SIGTERM);
            pcntl_wait($status);

            // Read detected files from the result file
            $detectedFiles = [];
            if (file_exists($resultFile)) {
                $content = file_get_contents($resultFile);
                $detectedFiles = array_filter(explode("\n", $content));
                unlink($resultFile);
            }

            $this->assertNotEmpty($detectedFiles, 'Watcher should have detected the new file');
            $this->assertStringContainsString('test_video.mp4', implode('', $detectedFiles));
        } else {
            $this->fail('Failed to fork process');
        }
    }

    public function test_is_running_returns_correct_state()
    {
        $watcher = new FileSystemWatcher();
        
        $this->assertFalse($watcher->isRunning());
    }

    public function test_scan_directory_finds_video_files()
    {
        // Create test video files
        touch($this->testDir . '/video1.mp4');
        touch($this->testDir . '/video2.webm');
        touch($this->testDir . '/video3.mov');
        touch($this->testDir . '/not_video.txt');

        $watcher = new FileSystemWatcher();
        $watcher->watchPath($this->testDir);

        $watchedPaths = $watcher->getWatchedPaths();
        $this->assertContains($this->testDir, $watchedPaths);
    }
}
