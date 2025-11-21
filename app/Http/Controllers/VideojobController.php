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
        $videoJob->prompt = 'skull face, Halloween, (sharp teeth:1.4), (mouth open:1.3), (dark skin:1.2), scull, night, dim light, darkness, looking to the viewer, eyes looking straight,  <lora:LowRA:0.3> <lora:more_details:0.5>';
        $videoJob->negative_prompt = 'bad-picture-chill-75v';
        $videoJob->status = 'pending';

        $videoJob->save();
        $this->persistMedia($videoJob, $fileInfo['path']);

        return response()->json([
            'url' => $videoJob->original_url,
            'status' => $videoJob->status,
            'id' => $videoJob->id,
        ]);
    }

    public function submitDeforum(Request $request): JsonResponse
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
        ]);

        $frameCount = $request->input('frameCount', 1);
        $videoJob = Videojob::findOrFail($request->input('videoId'));

        if ($response = $this->assertOwner($videoJob)) {
            return $response;
        }

        $videoJob->model_id = $request->input('modelId');
        $videoJob->prompt = trim((string) $request->input('prompt'));
        $videoJob->negative_prompt = trim((string) $request->input('negative_prompt', ''));
        $videoJob->status = 'processing';
        $videoJob->progress = 5;
        $seed = $this->normalizeSeed((int) $request->input('seed', -1));

        $videoJob->fps = 24;
        $videoJob->generator = 'deforum';
        $videoJob->seed = $seed;
        $videoJob->length = $request->input('length', 4);
        $videoJob->frame_count = round($videoJob->length * $videoJob->fps);
        $videoJob->job_time = 3;
        $videoJob->estimated_time_left = ($videoJob->frame_count * 6) + 6;
        $videoJob->denoising = $request->input('denoising');
        $videoJob->queued_at = Carbon::now();
        $videoJob->save();

        $queueName = $frameCount > 1
            ? $this->resolveQueueName('MEDIUM_PRIORITY_QUEUE', 'medium')
            : $this->resolveQueueName('HIGH_PRIORITY_QUEUE', 'high');
        Log::info("Dispatching job with framecount {$frameCount} to queue {$queueName}");
        ProcessDeforumJob::dispatch($videoJob, $frameCount)->onQueue($queueName);

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

    public function submit(Request $request): JsonResponse
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
        ]);

        $seed = $this->normalizeSeed((int) $request->input('seed', -1));
        $frameCount = $request->input('frameCount', 1);

        $videoJob = Videojob::findOrFail($request->input('videoId'));

        if ($response = $this->assertOwner($videoJob)) {
            return $response;
        }

        $controlnet = $request->input('controlnet', []);

        if (! empty($controlnet)) {
            $videoJob->controlnet = json_encode($controlnet);
            Log::info('Got controlnet params: ' . json_encode($controlnet), ['controlnet' => json_decode($videoJob->controlnet)]);
        }

        $videoJob->model_id = $request->input('modelId');
        $videoJob->prompt = trim((string) $request->input('prompt'));
        $videoJob->negative_prompt = trim((string) $request->input('negative_prompt', ''));
        $videoJob->cfg_scale = $request->input('cfgScale');
        $videoJob->seed = $seed;
        $videoJob->status = 'processing';
        $videoJob->progress = 5;
        $videoJob->job_time = 3;
        $videoJob->estimated_time_left = ($frameCount * 6) + 6;
        $videoJob->denoising = $request->input('denoising');
        $videoJob->queued_at = Carbon::now();
        $videoJob->save();

        $queueName = $frameCount > 1
            ? $this->resolveQueueName('MEDIUM_PRIORITY_QUEUE', 'medium')
            : $this->resolveQueueName('HIGH_PRIORITY_QUEUE', 'high');
        Log::info("Dispatching job with framecount {$frameCount} to queue {$queueName}");
        ProcessVideoJob::dispatch($videoJob, $frameCount)->onQueue($queueName);

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

    public function finalizeDeforum(Request $request): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $request->validate([
            'modelId' => 'integer',
            'prompt' => 'string',
            'preset' => 'string',
            'length' => 'numeric|between:1,20',
        ]);

        $videoJob = Videojob::findOrFail($request->input('videoId'));

        if ($response = $this->assertOwner($videoJob)) {
            return $response;
        }

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
        ProcessDeforumJob::dispatch($videoJob, 0)->onQueue($this->resolveQueueName('LOW_PRIORITY_QUEUE', 'low'));

        return response()->json([
            'status' => $videoJob->status,
            'progress' => $videoJob->progress,
            'job_time' => $videoJob->job_time,
            'retries' => $videoJob->retries,
            'queued_at' => $videoJob->queued_at,
            'estimated_time_left' => $videoJob->estimated_time_left,
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

        $videoJob->resetProgress('approved');

        $videoJob->refresh();
        ProcessVideoJob::dispatch($videoJob, 0)->onQueue($this->resolveQueueName('LOW_PRIORITY_QUEUE', 'low'));

        return response()->json([
            'status' => $videoJob->status,
            'progress' => $videoJob->progress,
            'job_time' => $videoJob->job_time,
            'retries' => $videoJob->retries,
            'queued_at' => $videoJob->queued_at,
            'estimated_time_left' => $videoJob->estimated_time_left,
        ]);
    }

    public function cancelJob(Request $request): JsonResponse
    {
        if ($response = $this->guardAuthenticated()) {
            return $response;
        }

        $videoJob = Videojob::findOrFail($request->input('videoId'));

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
            'status' => $videoJob->status,
            'progress' => $videoJob->progress,
            'estimated_time_left' => $videoJob->estimated_time_left,
            'job_time' => $videoJob->job_time,
            'queued_at' => $videoJob->queued_at,
            'queue' => $videoJob->status === 'approved' ? $videoJob->getQueueInfo() : [],
        ]);
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
        $queue = env($envKey);

        return ! empty($queue) ? $queue : $default;
    }
}
