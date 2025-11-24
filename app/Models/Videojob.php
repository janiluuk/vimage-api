<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Support\InteractsWithMedia;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Support\Facades\Log;
use Spatie\Image\Manipulations;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\DB;

class Videojob extends Model implements HasMedia
{
    use InteractsWithMedia;
    const STATUS_FINISHED = 'finished';
    const STATUS_PREVIEW = 'preview';
    const STATUS_ERROR = 'error';
    const STATUS_PREPROCESSING = 'preprocessing';
    const STATUS_POST_PROCESSING = 'postprocessing';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';

    const STATUS_CANCELLED = 'cancelled';

    public const MEDIA_PREVIEW = 'preview';
    public const MEDIA_FINISHED = 'finished';
    public const MEDIA_ORIGINAL = 'original';

    public const MEDIA_TYPE_IMAGE = 'image';
    public const MEDIA_TYPE_ANIMATION = 'animation';
    public const MEDIA_TYPE_VIDEO = 'video';


    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'video_jobs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'filename',
        'original_filename',
        'original_url',
        'model_id',
        'prompt',
        'cfg_scale',
        'negative_prompt',
        'seed',
        'controlnet',
        'denoising',
        'width',
        'height',
        'generator',
        'revision',
        'audio_codec',
        'bitrate',
        'length',
        'fps',
        'generation_parameters',
        'mimetype',
        'frame_count',
        'preview_url',
        'preview_animation',
        'preview_img',
        'codec',
        'job_time',
        'estimated_time_left',
        'progress',
        'queued_at',
        'retries',
        'outfile',
        'soundtrack_path',
        'soundtrack_url',
        'soundtrack_mimetype',
        'status',
        'thumbnail',
        'url',
    ];
    protected $dates = ['queued_at'];
    public function verifyAndCleanPreviews()
    {

        if (!$this->hasPreviewAnimation() && !empty($this->preview_animation)) {
            Log::info('Removing preview animation due to missing file', ['file' => $this->getPreviewAnimationPath()]);

            $this->preview_animation = false;
        }
        if (!$this->hasPreviewImage() && !empty($this->preview_img)) {
            Log::info('Removing preview image due to missing file', ['file' => $this->getPreviewImagePath()]);

            $this->preview_img = false;
        }
        if (!$this->hasFinishedVideo() && !empty($this->url)) {
            Log::info('Removing finished video due to missing file', ['file' => $this->getFinishedVideoPath()]);
            $this->url = false;
        }
        $this->save();
        return;
    }
    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function finished()
    {
        return $this->morphOneMedia()->where('collection_name', static::MEDIA_FINISHED);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function previewImages()
    {
        return $this->morphManyMedia()->where('collection_name', static::MEDIA_PREVIEW);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function previewAnimations()
    {
        return $this->morphManyMedia()->where('collection_name', static::MEDIA_PREVIEW);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function original()
    {
        return $this->morphManyMedia()->where('collection_name', static::MEDIA_ORIGINAL);
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this
            ->addMediaConversion('thumbnail')
            ->fit(Manipulations::FIT_CROP, 500, 500)
            ->nonQueued();
        $this
            ->addMediaConversion('backdrop')
            ->fit(Manipulations::FIT_CROP, 1280, 720)
            ->nonQueued();
        $this
            ->addMediaConversion('poster')
            ->fit(Manipulations::FIT_CROP, 960, 480)
            ->nonQueued();
    }
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'queued_at' => 'timestamp',
        'generation_parameters' => 'array',
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function modelfile()
    {
        return $this->belongsTo(ModelFile::class, 'model_id');
    }

    public function getCurrentRevision($type = self::MEDIA_TYPE_IMAGE, $collection = self::MEDIA_PREVIEW)
    {
        $generationTime = 0;
        $latestItem = null;
        $mediaItems = $this->getMedia($collection, ['type' => $type, 'revision' => $this->revision]);
        if (!empty($mediaItems)) {
            foreach ($mediaItems as $item) {
                // Log::info("scanning item: ", ['item' => $item]);
                if ($item->getCustomProperty('generated_at') > $generationTime)
                    $latestItem = $item;
            }
        }
        return $latestItem;
    }

    public function getRevisions(): array
    {
        $revisions = [];
        $generationTime = 0;

        foreach ($this->getMedia("preview") as $mediaItem) {

            if (!$mediaItem->hasCustomProperty('revision')) {
                $revision = md5($mediaItem->getCustomProperty('generation_parameters'));
            } else {
                $revision = $mediaItem->getCustomProperty('revision');
            }
            $type = $mediaItem->getCustomProperty('type');
            if (!empty($revisions[$revision]) && !empty($revisions[$revision][$type]) && ($revisions[$revision][$type]['generated_at'] < $mediaItem->getCustomProperty('generated_at')) || (empty($revisions[$revision]) || empty($revisions[$revision][$type]))) {

                if (!isset($revisions[$revision]['generated_at']) || $revisions[$revision]['generated_at'] < $mediaItem->getCustomProperty('generated_at'))
                    $revisions[$revision]['generated_at'] = $mediaItem->getCustomProperty('generated_at');
                $revisions[$revision][$type] = ['type' => $type, 'generation_parameters' => json_decode($mediaItem->getCustomProperty('generation_parameters')), 'generated_at' => $mediaItem->getCustomProperty('generated_at'), 'url' => $mediaItem->getFullUrl()];


            }



        }
        foreach ($this->getMedia("finished") as $mediaItem) {

            if (!$mediaItem->hasCustomProperty('revision')) {
                $revision = md5($mediaItem->getCustomProperty('generation_parameters'));
            } else {
                $revision = $mediaItem->getCustomProperty('revision');
            }

            $type = $mediaItem->getCustomProperty('type');
            if (!empty($revisions[$revision]) && !empty($revisions[$revision][$type]) && ($revisions[$revision][$type]['generated_at'] < $mediaItem->getCustomProperty('generated_at')) || (empty($revisions[$revision]) || empty($revisions[$revision][$type]))) {

                if (!isset($revisions[$revision]['generated_at']) || $revisions[$revision]['generated_at'] < $mediaItem->getCustomProperty('generated_at'))
                    $revisions[$revision]['generated_at'] = $mediaItem->getCustomProperty('generated_at');
                $revisions[$revision][$type] = ['type' => $type, 'generation_parameters' => json_decode($mediaItem->getCustomProperty('generation_parameters')), 'generated_at' => $mediaItem->getCustomProperty('generated_at'), 'url' => $mediaItem->getFullUrl()];
                $revisions[$revision]['revision'] = $revision;
            }

        }
        uasort($revisions, function ($a, $b) {
            if ($a['generated_at'] > $b['generated_at']) {
                return 1;
            } else {
                return -1;
            }
        });
        return $revisions;
    }

    public function getMediaFilesForRevision($type = self::MEDIA_TYPE_IMAGE, $collection = self::MEDIA_PREVIEW): array
    {
        $currentCollection = [];
        $generationTime = 0;

        foreach ($this->getMedia($collection) as $mediaItem) {
            if ($mediaItem->hasCustomProperty('revision') || $collection == 'original' || empty($this->revision)) {

                if ($mediaItem->getCustomProperty('revision') == $this->revision || $collection == 'original' || empty($this->revision)) {

                    $mediaType = $mediaItem->hasCustomProperty('type') ? $mediaItem->getCustomProperty('type') : null;
                    if (!$mediaType)
                        $mediaType = "unknown";
                    if ($mediaType !== $type)
                        continue;
                    // Log::info("Found match for revision", ['type' => $mediaType, 'properties' => $mediaItem ]);
                    $generationTime = $mediaItem->hasCustomProperty('generator') ? $mediaItem->getCustomProperty('generated_at') : 0;

                    if (empty($currentCollection) || $collection == 'original' || (!empty($currentCollection) && $generationTime > $currentCollection['generated_at'])) {
                        Log::info("Overwriting with mediaitem", ['type' => $mediaType, 'properties' => $mediaItem ]);
                        $currentCollection = [
                            'generator' => $mediaItem->hasCustomProperty('generator') ? $mediaItem->getCustomProperty('generator') : "",
                            'generated_at' => $generationTime,
                            'url' => $mediaItem->getFullUrl(),
                            'images' => [
                                'original' => $mediaItem->getFullUrl(),
                                'poster' => $mediaItem->getAvailableFullUrl(['poster']),
                                'thumbnail' => $mediaItem->getAvailableFullUrl(['thumbnail']),
                                'backdrop' => $mediaItem->getAvailableFullUrl(['backdrop']),
                            ],
                            'size' => $mediaItem->size,
                            'mimetype' => $mediaItem->mime_type,
                            'type' => $type
                        ];
                    }
                }
            }
        }

        return $currentCollection;
    }
    public function getPreviewMedia()
    {
        return $this->getMedia('preview', ['type' => self::MEDIA_TYPE_IMAGE])->toArray();
    }

    public function getPreviewAnimationMedia()
    {
        return $this->getMedia('preview', ['type' => self::MEDIA_TYPE_ANIMATION])->toArray();
    }

    public function getFinishedMedia()
    {
        return $this->getMedia('finished')->toArray();
    }

    public function getOriginalMedia()
    {
        return $this->getMedia('original')->toArray();
    }

    public function getOriginalVideoPath(): string
    {
        return public_path('videos/' . $this->filename);
    }

    public function getFinishedVideoPath(): string
    {
        $videoPath = config('app.paths.processed');
        return sprintf('%s/%s', $videoPath, basename($this->outfile));
    }

    public function getPreviewAnimationPath(): string
    {
        $previewPath = config('app.paths.preview');
        return sprintf('%s/%s', $previewPath, basename($this->preview_animation));
    }

    public function getPreviewImagePath(): string
    {
        $previewPath = config('app.paths.preview');
        return sprintf('%s/%s', $previewPath, basename($this->preview_img));
    }
    private function filterFilename($file): ?string
    {
        if (empty($file))
            return $file;
        $qs = explode("?", $file);
        return count($qs) > 1 ? $qs[0] : $file;
    }
    public function hasPreviewImage()
    {
        Log::info("Checking path for preview image", ['image' => $this->getPreviewImagePath()]);
        return is_file($this->getPreviewImagePath()) && file_exists($this->getPreviewImagePath());
    }

    public function hasPreviewAnimation()
    {
        return is_file($this->getPreviewAnimationPath()) && file_exists($this->getPreviewAnimationPath());
    }

    public function hasFinishedVideo()
    {
        return is_file($this->getFinishedVideoPath()) && file_exists($this->getFinishedVideoPath());
    }

    public function addAttachment($file, $type = self::MEDIA_TYPE_ANIMATION, $collection = 'preview', $generator = 'vid2vid')
    {
        $this->addMedia($file)->withCustomProperties(['generator' => $generator, 'revision' => $this->revision, 'type' => $type, 'generated_at' => time(), 'generation_parameters' => $this->generation_parameters])->withResponsiveImages()->preservingOriginal()->toMediaCollection($collection);
        Log::info('Added file {file} to media collection {collection} Is has now {size} images.', ['collection' => $collection, 'file' => $file, 'revision' => $this->revision, 'size' => count($this->getMedia($collection))]);
    }
    public function findMediaByGenerationParameters($parameters = [])
    {

        $media = [];
        foreach ((array) $this->getMedia('preview') as $item) {
            if ($item->hasCustomProperty('generation_parameters') && $item->getCustomProperty('generation_parameters') == $parameters) {
                $media[] = $item;
            }
        }
        foreach ((array) $this->getMedia('finished') as $item) {
            if ($item->hasCustomProperty('generation_parameters') && $item->getCustomProperty('generation_parameters') == $parameters) {
                $media[] = $item;
            }
        }
        return $media;
    }

    public function attachResults($generator='vid2vid')
    {
        $this->preview_img = $this->filterFilename($this->preview_img);
        $this->preview_animation = $this->filterFilename($this->preview_animation);

        if ($this->hasPreviewAnimation()) {
            $this->addAttachment($this->getPreviewAnimationPath(), self::MEDIA_TYPE_ANIMATION, self::MEDIA_PREVIEW, $generator);

            $previewAnimation = $this->getMediaFilesForRevision(self::MEDIA_TYPE_ANIMATION);
            if (!empty($previewAnimation)) {
                $this->preview_animation = $previewAnimation['url'];

                Log::info('assigning animation: ', ['item' => $previewAnimation]);

            }
            $this->save();

        }
        if ($this->hasPreviewImage()) {
            $this->addAttachment($this->getPreviewImagePath(),  self::MEDIA_TYPE_IMAGE, 'preview', $generator);

            $previewImage = $this->getMediaFilesForRevision(self::MEDIA_TYPE_IMAGE);

            if (!empty($previewImage)) {
                $this->preview_img = $previewImage['url'];
                $this->save();
                Log::info('assigning image: ', ['item' => $previewImage]);
            }
        }
        if ($this->hasFinishedVideo()) {
            try {
                $this->addAttachment($this->getFinishedVideoPath(), self::MEDIA_TYPE_VIDEO, self::MEDIA_FINISHED, $generator);
                $video = $this->getMediaFilesForRevision(self::MEDIA_TYPE_VIDEO, self::MEDIA_FINISHED);

                if (!empty($video)) {
                    $this->url = $video['url'];
                    Log::info('assigning video: ', ['item' => $video]);
                }
            } catch (\Exception $e) {
                Log::info("Ditching " . $this->url . " due to invalid conversion..");
                $this->url = null;
                $this->save();
            }
        }

        $this->save();

    }

    public function updateProgress($time = 0, $progress = 0, $estimated_time_left = 0)
    {
        $this->job_time = $time;
        $this->progress = $progress;
        $this->estimated_time_left = $estimated_time_left;
        return $this;
    }
    public function resetProgress($status = 'approved')
    {
        $this->status = $status;
        $this->updateProgress(0, 0, 0);
        $this->queued_at = $status == 'approved' ? \Carbon\Carbon::now() : null;
        $this->save();
        return $this;
    }

    public function getUrl()
    {
        $finished = $this->getMedia("finished");
        if (!empty($finished)) {
            return $finished[0]->getFullUrl();
        }
        return $this->url;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('finished')->useDisk('storage')->registerMediaConversions(function (Media $media) {
            $this
                ->addMediaConversion('thumbnail')
                ->width(150)
                ->height(150);

            $this
                ->addMediaConversion('backdrop')
                ->width(640)
                ->height(360);
            $this
                ->addMediaConversion('poster')
                ->width(360)
                ->height(640);
        });
        $this->addMediaCollection('preview')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/apng', 'image/webp'])->onlyKeepLatest(20);
        $this->addMediaCollection('thumbnails')->withResponsiveImages()->acceptsMimeTypes(['image/jpeg', 'image/png'])->onlyKeepLatest(3);
        $this->addMediaCollection('original')->acceptsMimeTypes(['image/png', 'video/quicktime', 'video/webm', 'video/mp4', 'image/gif', 'image/webp', 'image/jpeg'])->onlyKeepLatest(3);


    }

    public function getQueueInfo(): ?array
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return [];
        }
        $jobId = $this->id;
        $info = [];

        // Amount of items in the queue
        $info['total_jobs_processing'] = DB::table('video_jobs')->where('status', 'processing')->count();
        $info['total_jobs_in_queue'] = DB::table('video_jobs')->where('status', 'approved')->count();

        $modelId = $this->model_id;
        $queuedAt = $this->queued_at ?? now()->timestamp;
        $info['your_position'] = DB::table('video_jobs')
            ->where('status', 'approved')
            ->where(function ($query) use ($queuedAt) {
                $query->where('queued_at', '<', $queuedAt)
                    ->orWhere(function ($nested) use ($queuedAt) {
                        $nested->where('queued_at', $queuedAt)
                            ->where('id', '<=', $this->id);
                    });
            })
            ->count();

        // Calculate the average time per frame for previous jobs with the same model
        $previousJobs = DB::table('video_jobs')
            ->where('model_id', $modelId)
            ->where('status', 'finished')
            ->get();

        if ($previousJobs->isEmpty()) {
            $info['your_estimated_time'] = round($this->frame_count * 10);
        }

        $totalTime = 0;
        $totalFrames = 0;

        foreach ($previousJobs as $job) {
            if ($job->job_time == 0 || $job->frame_count == 0)
                continue;
            $totalTime += $job->job_time;
            $totalFrames += $job->frame_count;
        }
        if ($totalTime == 0 || $totalFrames == 0) {
            $averageTimePerFrame = 10;
        } else {
            $averageTimePerFrame = round($totalTime / $totalFrames);
        }
        // Estimated time for all jobs in the queue
        $totalFramesInQueue = DB::table('video_jobs')
            ->where('model_id', $modelId)
            ->where('status', self::STATUS_APPROVED)
            ->sum('frame_count');

        $currentJobsEstimate = DB::table('video_jobs')
            ->where('status', self::STATUS_PROCESSING)
            ->sum('estimated_time_left');

        if ($totalFramesInQueue > 0) {
            $info['estimated_time_for_all_jobs'] = round($currentJobsEstimate + ($averageTimePerFrame * $totalFramesInQueue));
        }
        // Estimated time for your job
        $info['your_estimated_time'] = round($averageTimePerFrame * $this->frame_count);
        // Estimated time for all jobs in the queue


        $info['estimated_time_processing_jobs'] = $currentJobsEstimate;

        return $info;
    }

}