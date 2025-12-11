<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDeforumJob;
use App\Jobs\ProcessVideoJob;
use App\Models\Videojob;
use App\Services\VideoProcessingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VideojobController extends Controller
{
    private VideoProcessingService $videoProcessingService;

    public function __construct(VideoProcessingService $videoProcessingService)
    {
        $this->videoProcessingService = $videoProcessingService;
    }

    public function upload(Request $request): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $validated = $request->validate([
            'attachment' => 'required|mimes:webm,mp4,mov,ogg,qt,gif,jpg,jpeg,png,webp|max:200000',
            'soundtrack' => 'nullable|file|mimes:mp3,aac,wav|max:51200',
            'type' => 'required|in:vid2vid,deforum',
        ]);

        return $validated['type'] === 'deforum'
            ? $this->handleDeforum($request)
            : $this->handleVid2Vid($request);
    }

    private function handleVid2Vid(Request $request): JsonResponse
    {
        $fileInfo = $this->persistUploadedFile($request);

        $videoJob = new Videojob();
        $videoJob->filename = $fileInfo['filename'];
        $videoJob->original_filename = $fileInfo['originalName'];
        $videoJob->outfile = $fileInfo['outfile'];
        $videoJob->model_id = 1;
        $videoJob->cfg_scale = 7;
        $videoJob->mimetype = $fileInfo['mimeType'];
        $videoJob->seed = -1;
        $videoJob->user_id = auth('api')->id();
        $videoJob->prompt = '';
        $videoJob->negative_prompt = '';
        $videoJob->queued_at = null;
        $videoJob->status = 'pending';

        $this->attachSoundtrack($videoJob, $request);

        $videoJob = $this->videoProcessingService->parseJob($videoJob, $fileInfo['publicPath']);
        $this->persistMedia($videoJob, $fileInfo['path']);

        return response()->json([
            'url' => $videoJob->original_url,
            'status' => $videoJob->status,
            'id' => $videoJob->id,
        ]);
    }

    private function handleDeforum(Request $request): JsonResponse
    {
        $fileInfo = $this->persistUploadedFile($request);

        $videoJob = new Videojob();
        $videoJob->filename = $fileInfo['filename'];
        $videoJob->original_filename = $fileInfo['originalName'];
        $videoJob->generator = 'deforum';
        $videoJob->outfile = $fileInfo['outfile'];
        $videoJob->model_id = 1;
        $videoJob->mimetype = $fileInfo['mimeType'];
        $videoJob->queued_at = null;
        $videoJob->seed = -1;
        $videoJob->frame_count = 90;
        $videoJob->user_id = auth('api')->id();
        $videoJob->prompt = '';
        $videoJob->negative_prompt = '';
        $videoJob->status = 'pending';

        $this->attachSoundtrack($videoJob, $request);

        $videoJob->save();
        $this->persistMedia($videoJob, $fileInfo['path']);

        return response()->json([
            'url' => $videoJob->original_url,
            'status' => $videoJob->status,
            'id' => $videoJob->id,
        ]);
    }

    
    public function generate(Request $request): JsonResponse
    {
        // Validate common parameters first
        $request->validate([
            'videoId' => 'required|integer|exists:video_jobs,id',
            'type' => 'required|in:vid2vid,deforum',
        ]);

        $type = $request->input('type');

        return $type === 'deforum'
            ? $this->generateDeforum($request)
            : $this->generateVid2Vid($request);
    }


private function generateDeforum(Request $request): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $request->validate([
            'modelId' => 'required|integer',
            'prompt' => 'required|string',
            'frameCount' => 'numeric|between:1,20',
            'preset' => 'required|string',
            'length' => 'numeric|between:1,20',
            'extendFromJobId' => 'nullable|integer|exists:video_jobs,id',
        ]);

        $frameCount = $request->input('frameCount', 1);
        $videoJob = Videojob::findOrFail($request->input('videoId'));

        $extendFromJobId = $request->input('extendFromJobId');
        if ($extendFromJobId) {
            $baseJob = Videojob::findOrFail($extendFromJobId);

            if ($baseJob->generator !== 'deforum') {
                return response()->json(['message' => 'Only deforum jobs can be extended'], 422);
            }

            if ($response = $this->assertOwner($baseJob)) {
                return $response;
            }

            $persistedParameters = json_decode((string) $baseJob->generation_parameters, true) ?? [];

            // When extending, inherit model_id from base job (not overridable)
            $videoJob->model_id = $persistedParameters['model_id'] ?? $baseJob->model_id;
            
            // These parameters can be overridden by request
            $videoJob->prompt = $request->input('prompt', $persistedParameters['prompts']['positive'] ?? $baseJob->prompt);
            $videoJob->negative_prompt = $request->input('negative_prompt', $persistedParameters['prompts']['negative'] ?? $baseJob->negative_prompt);
            $videoJob->length = $request->input('length', $persistedParameters['length'] ?? $baseJob->length);
            
            // These parameters come from base job only
            $videoJob->seed = $request->input('seed', $persistedParameters['seed'] ?? $baseJob->seed);
            $videoJob->denoising = $request->input('denoising', $persistedParameters['denoising'] ?? $baseJob->denoising);
            $videoJob->fps = $persistedParameters['fps'] ?? $baseJob->fps;
            $videoJob->frame_count = $persistedParameters['frame_count'] ?? $baseJob->frame_count;
            $videoJob->width = $baseJob->width;
            $videoJob->height = $baseJob->height;
        } else {
            $videoJob->model_id = $request->input('modelId', $videoJob->model_id);
            $videoJob->prompt = trim((string) $request->input('prompt', $videoJob->prompt));
            $videoJob->negative_prompt = trim((string) $request->input('negative_prompt', $videoJob->negative_prompt));
            $videoJob->length = $request->input('length', $videoJob->length ?? 4);
            $videoJob->denoising = $request->input('denoising', $videoJob->denoising);
        }

        if ($response = $this->assertOwner($videoJob)) {
            return $response;
        }

        $videoJob->status = 'processing';
        $videoJob->progress = 5;
        $seed = $this->normalizeSeed((int) $request->input('seed', $videoJob->seed ?? -1));

        $videoJob->fps = $videoJob->fps ?? 24;
        $videoJob->generator = 'deforum';
        $videoJob->seed = $seed;
        $videoJob->frame_count = round($videoJob->length * $videoJob->fps);
        $videoJob->job_time = 3;
        $videoJob->estimated_time_left = ($videoJob->frame_count * 6) + 6;
        $videoJob->queued_at = Carbon::now();
        $videoJob->save();

        $queueName = $frameCount > 1
            ? $this->resolveQueueName('MEDIUM_PRIORITY_QUEUE', 'medium')
            : $this->resolveQueueName('HIGH_PRIORITY_QUEUE', 'high');
        Log::info("Dispatching job with framecount {$frameCount} to queue {$queueName}");
        ProcessDeforumJob::dispatch($videoJob, $frameCount, $extendFromJobId)->onQueue($queueName);

        return response()->json([
            'id' => $videoJob->id,
            'status' => $videoJob->status,
            'seed' => $videoJob->seed,
            'job_time' => $videoJob->job_time,
            'progress' => $videoJob->progress,
            'estimated_time_left' => $videoJob->estimated_time_left,
            'width' => $videoJob->width,
            'height' => $videoJob->height,
            'length' => $videoJob->length,
            'fps' => $videoJob->fps,
        ]);
    }

    private function generateVid2Vid(Request $request): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $request->validate([
            'modelId' => 'required|integer',
            'cfgScale' => 'required|integer|between:2,10',
            'prompt' => 'required|string',
            'frameCount' => 'numeric|between:1,20',
            'denoising' => 'required|numeric|between:0.1,1.0',
            'seed' => 'nullable|integer',
            'negative_prompt' => 'nullable|string',
            'controlnet' => 'nullable|array',
            'extendFromJobId' => 'nullable|integer|exists:video_jobs,id',
        ]);

        $seed = $this->normalizeSeed((int) $request->input('seed', -1));
        $frameCount = $request->input('frameCount', 1);

        $videoJob = Videojob::findOrFail($request->input('videoId'));

        if ($response = $this->assertOwner($videoJob)) {
            return $response;
        }

        // Handle job extension
        $extendFromJobId = $request->input('extendFromJobId');
        if ($extendFromJobId) {
            $baseJob = Videojob::findOrFail($extendFromJobId);

            if ($baseJob->generator === 'deforum') {
                return response()->json(['message' => 'Cannot extend deforum jobs with vid2vid'], 422);
            }

            if ($response = $this->assertOwner($baseJob)) {
                return $response;
            }

            // Use last frame of base job as init image for extension
            if (!empty($baseJob->last_frame_path) && file_exists($baseJob->last_frame_path)) {
                // Copy the last frame to use as the new job's original video
                $videosPath = config('app.paths.videos', 'videos');
                $targetPath = public_path($videosPath . '/' . $videoJob->id . '_extend_init.png');
                
                // Ensure directory exists
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                
                if (copy($baseJob->last_frame_path, $targetPath)) {
                    Log::info('Using last frame from base job as init image', [
                        'base_job_id' => $baseJob->id,
                        'last_frame' => $baseJob->last_frame_path,
                        'target_path' => $targetPath,
                    ]);
                } else {
                    Log::warning('Failed to copy last frame for init image', [
                        'base_job_id' => $baseJob->id,
                        'last_frame' => $baseJob->last_frame_path,
                        'target_path' => $targetPath,
                    ]);
                }
            }

            $persistedParameters = json_decode((string) $baseJob->generation_parameters, true) ?? [];

            // Set defaults from base job (these will be overridden if provided in request)
            $videoJob->model_id = $request->input('modelId', $persistedParameters['model_id'] ?? $baseJob->model_id);
            $videoJob->cfg_scale = $request->input('cfgScale', $persistedParameters['cfg_scale'] ?? $baseJob->cfg_scale);
            $videoJob->denoising = $request->input('denoising', $persistedParameters['denoising_strength'] ?? $baseJob->denoising);
            $videoJob->prompt = $request->input('prompt', $persistedParameters['prompt'] ?? $baseJob->prompt);
            $videoJob->negative_prompt = $request->input('negative_prompt', $persistedParameters['negative_prompt'] ?? $baseJob->negative_prompt);
            $videoJob->seed = $request->input('seed', $persistedParameters['seed'] ?? $baseJob->seed);
            $videoJob->fps = $persistedParameters['fps'] ?? $baseJob->fps;
            $videoJob->width = $baseJob->width;
            $videoJob->height = $baseJob->height;
        } else {
            $videoJob->model_id = $request->input('modelId');
            $videoJob->cfg_scale = $request->input('cfgScale');
            $videoJob->denoising = $request->input('denoising');
            $videoJob->prompt = trim((string) $request->input('prompt'));
            $videoJob->negative_prompt = trim((string) $request->input('negative_prompt', ''));
        }

        $controlnet = $request->input('controlnet', []);

        if (! empty($controlnet)) {
            $videoJob->controlnet = json_encode($controlnet);
            Log::info('Got controlnet params: ' . json_encode($controlnet), ['controlnet' => json_decode($videoJob->controlnet)]);
        }

        $videoJob->seed = $seed;
        $videoJob->status = 'processing';
        $videoJob->progress = 5;
        $videoJob->job_time = 3;
        $videoJob->estimated_time_left = ($frameCount * 6) + 6;
        $videoJob->queued_at = Carbon::now();
        $videoJob->save();

        $queueName = $frameCount > 1
            ? $this->resolveQueueName('MEDIUM_PRIORITY_QUEUE', 'medium')
            : $this->resolveQueueName('HIGH_PRIORITY_QUEUE', 'high');
        Log::info("Dispatching job with framecount {$frameCount} to queue {$queueName}");
        ProcessVideoJob::dispatch($videoJob, $frameCount, $extendFromJobId)->onQueue($queueName);

        return response()->json([
            'id' => $videoJob->id,
            'status' => $videoJob->status,
            'seed' => $videoJob->seed,
            'job_time' => $videoJob->job_time,
            'progress' => $videoJob->progress,
            'estimated_time_left' => $videoJob->estimated_time_left,
            'width' => $videoJob->width,
            'height' => $videoJob->height,
            'length' => $videoJob->length,
            'fps' => $videoJob->fps,
        ]);
    }

    public function finalize(Request $request): JsonResponse
{
    if ($response = $this->guardAuthenticated()) {
        return $response;
    }

    $videoJob = Videojob::findOrFail($request->input('videoId'));

    if ($response = $this->assertOwner($videoJob)) {
        return $response;
    }

    if ($videoJob->generator === 'deforum') {
        $request->validate([
            'modelId' => 'integer',
            'prompt' => 'string',
            'preset' => 'string',
            'length' => 'numeric|between:1,20',
        ]);

        $videoJob->resetProgress('approved');
        $videoJob->fps = 24;
        $videoJob->seed = $this->normalizeSeed((int) $request->input('seed', -1));
        $videoJob->model_id = $request->input('modelId', $videoJob->model_id);
        $videoJob->prompt = trim((string) $request->input('prompt', $videoJob->prompt));
        $videoJob->negative_prompt = trim((string) $request->input('negative_prompt', $videoJob->negative_prompt));
        $videoJob->length = $request->input('length', $videoJob->length);
        $videoJob->frame_count = round($videoJob->length * $videoJob->fps);
        $videoJob->save();

        $videoJob->refresh();
        ProcessDeforumJob::dispatch($videoJob, 0, null)->onQueue($this->resolveQueueName('LOW_PRIORITY_QUEUE', 'low'));
    } else {
        $videoJob->resetProgress('approved');

        $videoJob->refresh();
        ProcessVideoJob::dispatch($videoJob, 0, null)->onQueue($this->resolveQueueName('LOW_PRIORITY_QUEUE', 'low'));
    }

    return response()->json([
        'status' => $videoJob->status,
        'progress' => $videoJob->progress,
        'job_time' => $videoJob->job_time,
        'retries' => $videoJob->retries,
        'queued_at' => $this->queuedAtTimestamp($videoJob->queued_at),
        'estimated_time_left' => $videoJob->estimated_time_left,
    ]);
}

public function cancelJob(int $videoId): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $videoJob = Videojob::findOrFail($videoId);

        if ($response = $this->assertOwner($videoJob)) {
            return $response;
        }

        $videoJob->resetProgress('cancelled');

        return response()->json([
            'status' => $videoJob->status,
            'progress' => 0,
            'job_time' => 0,
            'estimated_time_left' => 0,
        ]);
    }

    public function status(int $id): JsonResponse
    {
        $videoJob = Videojob::findOrFail($id);

        return response()->json([
            'id' => $videoJob->id,
            'status' => $videoJob->status,
            'progress' => $videoJob->progress,
            'estimated_time_left' => $videoJob->estimated_time_left,
            'job_time' => $videoJob->job_time,
            'queued_at' => $this->queuedAtTimestamp($videoJob->queued_at),
            'queue' => $videoJob->status === 'approved' ? $videoJob->getQueueInfo() : [],
            'generator' => $videoJob->generator,
            'model_id' => $videoJob->model_id,
            'prompt' => $videoJob->prompt,
            'negative_prompt' => $videoJob->negative_prompt,
            'cfg_scale' => $videoJob->cfg_scale,
            'seed' => $videoJob->seed,
            'denoising' => $videoJob->denoising,
            'fps' => $videoJob->fps,
            'frame_count' => $videoJob->frame_count,
            'length' => $videoJob->length,
            'width' => $videoJob->width,
            'height' => $videoJob->height,
            'generation_parameters' => $videoJob->generation_parameters,
        ]);
    }

    private function queuedAtTimestamp($queuedAt): ?int
    {
        if (is_null($queuedAt)) {
            return null;
        }

        if (is_numeric($queuedAt)) {
            return (int) $queuedAt;
        }

        return $queuedAt instanceof \Carbon\CarbonInterface ? $queuedAt->timestamp : null;
    }

    public function getVideoJobs(): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $userId = auth('api')->id();
        $videoJobs = Videojob::where('user_id', $userId)->get();

        return response()->json($videoJobs);
    }

    public function processingStatus(): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $userId = auth('api')->id();

        $processingJobs = Videojob::where('user_id', $userId)
            ->where('status', Videojob::STATUS_PROCESSING)
            ->orderByDesc('updated_at')
            ->get();

        $queuedJobs = Videojob::where('user_id', $userId)
            ->where('status', Videojob::STATUS_APPROVED)
            ->orderBy('queued_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'processing' => $processingJobs->map(fn (Videojob $job) => $this->serializeJobStatus($job)),
            'queue' => $queuedJobs->map(fn (Videojob $job) => $this->serializeJobStatus($job, true)),
            'counts' => [
                'processing' => Videojob::where('status', Videojob::STATUS_PROCESSING)->count(),
                'queued' => Videojob::where('status', Videojob::STATUS_APPROVED)->count(),
            ],
        ]);
    }

    public function processingQueue(): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $userId = auth('api')->id();

        $queueJobs = Videojob::where('user_id', $userId)
            ->whereIn('status', [Videojob::STATUS_APPROVED, Videojob::STATUS_PROCESSING])
            ->orderByRaw('queued_at IS NULL')
            ->orderBy('queued_at')
            ->orderBy('id')
            ->get();

        return response()->json(
            $queueJobs->map(fn (Videojob $job) => $this->serializeJobStatus($job, true))
        );
    }

    private function persistUploadedFile(Request $request): array
    {
        $uploadedFile = $request->file('attachment');
        $path = $uploadedFile->store('videos', 'public');
        $filename = basename($path);

        $publicDirectory = public_path('videos');
        if (! is_dir($publicDirectory)) {
            mkdir($publicDirectory, 0755, true);
        }

        $storagePath = Storage::disk('public')->path($path);
        copy($storagePath, $publicDirectory . '/' . $filename);

        return [
            'filename' => $filename,
            'originalName' => $uploadedFile->getClientOriginalName(),
            'outfile' => pathinfo($filename, PATHINFO_FILENAME) . '.mp4',
            'path' => $path,
            'publicPath' => $publicDirectory . '/' . $filename,
            'mimeType' => $uploadedFile->getMimeType(),
        ];
    }

    private function persistMedia(Videojob $videoJob, string $path): void
    {
        $videoJob->save();
        $videoJob->addMedia($path)
            ->withResponsiveImages()
            ->preservingOriginal()
            ->toMediaCollection(Videojob::MEDIA_ORIGINAL);

        $videoJob->original_url = $videoJob->getMedia(Videojob::MEDIA_ORIGINAL)->first()?->getFullUrl();
        $videoJob->save();
    }

    private function attachSoundtrack(Videojob $videoJob, Request $request): void
    {
        $soundtrack = $this->persistSoundtrack($request);

        if (! $soundtrack) {
            return;
        }

        $videoJob->soundtrack_path = $soundtrack['absolutePath'];
        $videoJob->soundtrack_url = $soundtrack['url'];
        $videoJob->soundtrack_mimetype = $soundtrack['mimeType'];
    }

    private function persistSoundtrack(Request $request): ?array
    {
        if (! $request->hasFile('soundtrack')) {
            return null;
        }

        $soundtrack = $request->file('soundtrack');
        $path = $soundtrack->store('soundtracks', 'public');

        return [
            'absolutePath' => Storage::disk('public')->path($path),
            'url' => Storage::disk('public')->url($path),
            'mimeType' => $soundtrack->getMimeType(),
        ];
    }

    private function guardAuthenticated(): ?JsonResponse
    {
        if (! auth('api')->id()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        return null;
    }

    private function assertOwner(Videojob $videoJob): ?JsonResponse
    {
        if ($videoJob->user_id !== auth('api')->id()) {
            return response()->json(['error' => 'Unauthorized. Not your video.'], 403);
        }

        return null;
    }

    private function normalizeSeed(int $seed): int
    {
        return $seed > 0 ? $seed : rand(1, 4294967295);
    }

    private function resolveQueueName(string $envKey, string $default): string
    {
        // Note: Queue names should be defined in config/queue.php for proper config caching
        // For now, using env() with a fallback. Consider moving to config file.
        $queue = config("queue.names.{$envKey}", env($envKey));

        return ! empty($queue) ? $queue : $default;
    }

    private function serializeJobStatus(Videojob $videoJob, bool $includeQueueInfo = false): array
    {
        return [
            'id' => $videoJob->id,
            'status' => $videoJob->status,
            'progress' => $videoJob->progress,
            'estimated_time_left' => $videoJob->estimated_time_left,
            'job_time' => $videoJob->job_time,
            'queued_at' => $this->queuedAtTimestamp($videoJob->queued_at),
            'generator' => $videoJob->generator,
            'model_id' => $videoJob->model_id,
            'prompt' => $videoJob->prompt,
            'negative_prompt' => $videoJob->negative_prompt,
            'frame_count' => $videoJob->frame_count,
            'queue' => $includeQueueInfo ? $videoJob->getQueueInfo() : [],
        ];
    }
}
