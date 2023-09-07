<?php
 
namespace App\Helpers;
 
use FFMpeg\Coordinate\Dimension;
use FFMpeg\FFMpeg;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Format\Video\X264;
use FFMpeg\Media\Video;
 
class VideoManager
{
 
    /**
     * @var FFMpeg
     */
    private $ffmpeg;
 
    /**
     * @var int
     */
    private $videoMaxHeight;
 
    /**
     * @var int
     */
    private $videoMaxWidth;
 
    /**
     * @var Video|null
     */
    private $video;
 
    /**
     * @var string|null
     */
    private $videoPath;
 
    /**
     * @param FFMpeg $ffmpeg
     * @param int $videoMaxHeight
     * @param int $videoMaxWidth
     */
    public function __construct(
        FFMpeg $ffmpeg,
        int $videoMaxHeight,
        int $videoMaxWidth
    )
    {
        $this->ffmpeg = $ffmpeg;
        $this->videoMaxHeight = $videoMaxHeight;
        $this->videoMaxWidth = $videoMaxWidth;
    }
 
    /**
     * @param string|null $videoPath
     * @return VideoManager
     */
    public function setVideoPath(?string $videoPath): VideoManager
    {
        $this->videoPath = $videoPath;
        if (!file_exists($videoPath)) {
            throw new \Exception('video does not exists:' . $videoPath);
        }
        $this->video = $this->ffmpeg->open($videoPath);
 
        return $this;
    }
 
 
 
    /**
     * @return int
     */
    public function getHeight(): int
    {
        return $this->video->getStreams()->first()->getDimensions()->getHeight();
    }
 
    /**
     * @return int
     */
    public function getWidth(): int
    {
        return $this->video->getStreams()->first()->getDimensions()->getWidth();
    }
 
    /**
     * @return void
     */
    public function formatH264Codec()
    {
        $format = new X264();
 
        $tmpDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'video_format' . DIRECTORY_SEPARATOR;
        if (!is_dir($tmpDirectory)) {
            mkdir($tmpDirectory);
        }
 
        $tmpFilePath = $tmpDirectory . uniqid(time()) . '.mp4';
 
        $this->resizeVideo();
 
        $format
            ->setVideoCodec('libx264')
         ;
 
        $this->video->save($format, $tmpFilePath);
 
        // replace with encoded file
        unlink($this->videoPath);
        copy($tmpFilePath, $this->videoPath);
 
        //reset ffmpeg
        $this->ffmpeg = FFMpeg::create();
        $this->video = $this->ffmpeg->open($this->videoPath);
    }
 
    /**
     * @return void
     */
    protected function resizeVideo()
    {
        $width  = $this->getWidth();
        $height = $this->getHeight();
 
        if ($width > $this->videoMaxWidth || $height > $this->videoMaxHeight) {
            $factorWidth = $width / $this->videoMaxWidth;
            $factorHeight = $height / $this->videoMaxHeight;
 
            if ($factorWidth > $factorHeight) {
                $width = $width / $factorWidth;
                $height = $height / $factorWidth;
                $mode = ResizeFilter::RESIZEMODE_SCALE_WIDTH;
            } else {
                $width = $width / $factorHeight;
                $height = $height / $factorHeight;
                $mode = ResizeFilter::RESIZEMODE_SCALE_HEIGHT;
            }
 
            //round to the nearest odd number: ffmpeg otherwise throws an error
            $height = $this->roundToNextOddNumber($height);
            $width  = $this->roundToNextOddNumber($width);
 
            $this->video->filters()->resize(
                new Dimension($width, $height),
                $mode
            );
        }
    }
 
    /**
     * @param float $number
     * @return int
     */
    private function roundToNextOddNumber(float $number): int
    {
        $number = ceil($number); // Round up decimals to an integer
        if($number % 2 == 1) $number++;
 
        return $number;
    }
}