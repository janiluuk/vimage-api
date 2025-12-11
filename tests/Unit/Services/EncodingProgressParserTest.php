<?php

namespace Tests\Unit\Services;

use App\Services\VideoJobs\EncodingProgressParser;
use Tests\TestCase;

class EncodingProgressParserTest extends TestCase
{
    public function test_parses_ffmpeg_progress_output()
    {
        $parser = new EncodingProgressParser(1000);
        
        $line = "frame= 500 fps= 25 q=-1.0 size=   10240kB time=00:00:20.00 bitrate=4194.3kbits/s speed=1.0x";
        $progress = $parser->parseLine($line);

        $this->assertNotNull($progress);
        $this->assertEquals(500, $progress['frame']);
        $this->assertEquals(25.0, $progress['fps']);
        $this->assertEquals(50.0, $progress['progress_percent']);
    }

    public function test_parses_python_script_progress()
    {
        $parser = new EncodingProgressParser();
        
        $line = "Processing frame 250/1000 (25.0%)";
        $progress = $parser->parseLine($line);

        $this->assertNotNull($progress);
        $this->assertEquals(250, $progress['frame']);
        $this->assertEquals(1000, $progress['total_frames']);
        $this->assertEquals(25.0, $progress['progress_percent']);
    }

    public function test_parses_simple_progress_percentage()
    {
        $parser = new EncodingProgressParser(1000);
        
        $line = "Progress: 75.5%";
        $progress = $parser->parseLine($line);

        $this->assertNotNull($progress);
        $this->assertEquals(75.5, $progress['progress_percent']);
    }

    public function test_parses_step_based_progress()
    {
        $parser = new EncodingProgressParser();
        
        $line = "Step 50/100";
        $progress = $parser->parseLine($line);

        $this->assertNotNull($progress);
        $this->assertEquals(50, $progress['step']);
        $this->assertEquals(100, $progress['total_steps']);
        $this->assertEquals(50.0, $progress['progress_percent']);
    }

    public function test_returns_null_for_non_progress_lines()
    {
        $parser = new EncodingProgressParser();
        
        $line = "Some random log message";
        $progress = $parser->parseLine($line);

        $this->assertNull($progress);
    }

    public function test_parse_output_extracts_latest_progress()
    {
        $parser = new EncodingProgressParser(1000);
        
        $output = "Starting encoding...\n";
        $output .= "frame= 100 fps= 25 ...\n";
        $output .= "frame= 200 fps= 25 ...\n";
        $output .= "frame= 300 fps= 25 ...\n";
        
        $progress = $parser->parseOutput($output);

        $this->assertEquals(300, $progress['frame']);
        $this->assertEquals(30.0, $progress['progress_percent']);
    }

    public function test_calculate_eta()
    {
        $parser = new EncodingProgressParser(1000);
        
        // 50% progress in 100 seconds = 100 more seconds expected
        $eta = $parser->calculateETA(50.0, 100);
        
        $this->assertEquals(100, $eta);
    }

    public function test_calculate_eta_with_low_progress()
    {
        $parser = new EncodingProgressParser(1000);
        
        // 5% progress in 10 seconds: estimated total = (10 / 5) * 100 = 200 seconds
        // Remaining = 200 - 10 = 190 seconds
        $eta = $parser->calculateETA(5.0, 10);
        
        $this->assertEquals(190, $eta);
    }

    public function test_has_significant_change_detects_changes()
    {
        $parser = new EncodingProgressParser();
        
        // First call should always return true (change from 0)
        $this->assertTrue($parser->hasSignificantChange(5.0, 1.0));
        
        // Small change should return false
        $this->assertFalse($parser->hasSignificantChange(5.5, 1.0));
        
        // Large change should return true
        $this->assertTrue($parser->hasSignificantChange(10.0, 1.0));
    }

    public function test_set_total_frames()
    {
        $parser = new EncodingProgressParser();
        
        $parser->setTotalFrames(5000);
        
        $this->assertEquals(5000, $parser->getTotalFrames());
    }

    public function test_progress_never_exceeds_99_percent()
    {
        $parser = new EncodingProgressParser(100);
        
        $line = "frame= 100 fps= 25 ..."; // 100% progress
        $progress = $parser->parseLine($line);

        $this->assertLessThan(100.0, $progress['progress_percent']);
        $this->assertEquals(99.9, $progress['progress_percent']);
    }
}
