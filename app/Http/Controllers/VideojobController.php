<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideoJob;
use App\Models\Videojob;
use App\Services\VideoProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class VideojobController extends Controller
{
    private $videoProcessingService;

    public function __construct(VideoProcessingService $videoProcessingService)
    {
        $this->videoProcessingService = $videoProcessingService;
    }

    public function upload(Request $request)
    {   
        
        $request->validate([
            'attachment' => 'required|mimes:webm,mp4,mov,ogg,qt,gif,jpg,png,webp|max:200000',
            'type' => 'required|in:vid2vid,deforum',
        ]);

        $auth = auth('api');
        if (!$auth || !$auth->id()) {
            // Handle error, user is not authenticated
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Validate the video file
        $uploadedFile = $request->file('attachment');
        switch ($request->get('type', 'vid2vid')) {

            case 'vid2vid':
                return $this->handleVid2Vid($uploadedFile);
                break;
            case 'deforum':
                return $this->handleDeforum($uploadedFile);
                break;
            default:
                return $this->handleVid2Vid($uploadedFile);
        }

    }
    public function handleVid2Vid($uploadedFile)
    {
        $mimeType = $uploadedFile->getMimeType();

        // Store the video file
        $path = $uploadedFile->store('videos');

        $uploadedFile->move(public_path('videos'), basename($path));

        // Create a new VideoJob record
        $videoJob = new Videojob;
        $videoJob->filename = basename($path);
        $videoJob->original_filename = $uploadedFile->getClientOriginalName();
        $videoJob->outfile = preg_replace('/\.[^.]+$/', '.', basename($path)) . 'mp4';
        $videoJob->model_id = 1;
        $videoJob->cfg_scale = 7;
        $videoJob->mimetype = $mimeType;
        $videoJob->seed = -1;
        $videoJob->user_id = auth('api')->id();
        $videoJob->prompt = '';
        $videoJob->negative_prompt = '';
        $videoJob->status = 'pending';
        $filePath = public_path('videos/' . $videoJob->filename);
        $videoJob = $this->videoProcessingService->parseJob($videoJob, $filePath);
        $videoJob->save();
        $videoJob->addMedia($path)->withResponsiveImages()->preservingOriginal()->toMediaCollection(Videojob::MEDIA_ORIGINAL);
        $videoJob->original_url = $videoJob->getMedia(Videojob::MEDIA_ORIGINAL)->first()->getFullUrl();
        $videoJob->save();

        return response()->json([
            'url' => $videoJob->original_url,
            'status' => $videoJob->status,
            'id' => $videoJob->id,
        ]);

    }


    public function handleDeforum($uploadedFile)
    {
        $mimeType = $uploadedFile->getMimeType();

        // Store the video file
        $path = $uploadedFile->store('videos');

        $uploadedFile->move(public_path('videos'), basename($path));

        // Create a new VideoJob record
        $videoJob = new Videojob;
        $videoJob->filename = basename($path);
        $videoJob->original_filename = $uploadedFile->getClientOriginalName();
        $videoJob->generator = 'deforum';
        $videoJob->outfile = preg_replace('/\.[^.]+$/', '.', basename($path)) . 'mp4';
        $videoJob->model_id = 1;
        $videoJob->mimetype = $mimeType;

        $videoJob->user_id = auth('api')->id();
        $videoJob->prompt = '';
        $videoJob->negative_prompt = '';
        $videoJob->status = 'pending';

        $videoJob->save();
        $videoJob->addMedia($path)->withResponsiveImages()->preservingOriginal()->toMediaCollection(Videojob::MEDIA_ORIGINAL);
        $videoJob->original_url = $videoJob->getMedia(Videojob::MEDIA_ORIGINAL)->first()->getFullUrl();
        $videoJob->save();

        return response()->json([
            'url' => $videoJob->original_url,
            'status' => $videoJob->status,
            'id' => $videoJob->id,
        ]);

    }

    public function submit(Request $request)
    {
        $user_id = auth()->id(); // This will get the ID of the currently authenticated user

        if (!$user_id) {
            // Handle error, user is not authenticated
            return response()->json(['error' => 'Unauthenticated'], 401);
        }


        // Validate the form data
        $request->validate([
            'modelId' => 'required|integer',
            'cfgScale' => 'required|integer|between:2,10',
            'prompt' => 'required|string',
            'frameCount' => 'numeric|between:1,20',
            'denoising' => 'required|numeric|between:0.1,1.0',
        ]);

        $seed = $request->input('seed', -1);

        if ((int) $seed <= 0) {
            $seed = rand(1, 4294967295);
        }

        $frameCount = $request->input('frameCount', 1);

        // Get the VideoJob record and update it with the form data
        $videoJob = Videojob::findOrFail($request->input('videoId'));

        $controlnet = $request->input('controlnet', []);

        if (!empty($controlnet)) {
            $videoJob->controlnet = json_encode($controlnet);
            Log::info("Got controlnet params: " . json_encode($controlnet), ['controlnet' => json_decode($videoJob->controlnet)]);

        }


        $videoJob->model_id = $request->input('modelId');
        $videoJob->cfg_scale = $request->input('cfgScale');
        $videoJob->seed = $seed;
        $videoJob->prompt = trim($request->input('prompt'));
        $videoJob->negative_prompt = trim($request->input('negative_prompt'));
        $videoJob->status = 'processing';
        $videoJob->progress = 5;
        $videoJob->job_time = 3;
        $videoJob->estimated_time_left = ($frameCount * 6) + 6;
        $videoJob->denoising = $request->input('denoising');
        $videoJob->save();
        $queueName = $frameCount > 1 ? env('MEDIUM_PRIORITY_QUEUE') : env('HIGH_PRIORITY_QUEUE');
        Log::info("Dispatching job with framecount {$frameCount} to queue {$queueName}");
        ProcessVideoJob::dispatch($videoJob, $frameCount)->onQueue($queueName);

        return response()->json([
            'id' => $videoJob->id,
            'status' => $videoJob->status,
            'seed' => $videoJob->seed,
            //'previewAnimation' => $videoJob->preview_animation,
            // 'previewImg' => $videoJob->preview_img,
            'job_time' => $videoJob->job_time,
            'progress' => $videoJob->progress,
            'estimated_time_left' => $videoJob->estimated_time_left,
            'width' => $videoJob->width,
            'height' => $videoJob->height,
            'length' => $videoJob->length,
            'fps' => $videoJob->fps,
        ]);
    }

    public function finalize(Request $request)
    {
        $user_id = auth()->id(); // This will get the ID of the currently authenticated user

        if (!$user_id) {
            // Handle error, user is not authenticated
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $videoJob = Videojob::findOrFail($request->input('videoId'));
        $videoJob->resetProgress('approved');
        $videoJob->refresh();
        ProcessVideoJob::dispatch($videoJob, false)->onQueue(env('LOW_PRIORITY_QUEUE'));
        if ($videoJob) {
            return response()->json([
                'status' => $videoJob->status,
                'progress' => $videoJob->progress,
                'job_time' => $videoJob->job_time,
                'retries' => $videoJob->retries,
                'estimated_time_left' => $videoJob->estimated_time_left
            ]);
        } else {
            return response()->json(['error' => 'Job not found'], 404);
        }
    }
    public function cancelJob(Request $request)
    {

        $videoJob = Videojob::findOrFail($request->input('videoId'));

        $auth = auth('api');
        if (!$auth || !$auth->id()) {

            // Handle error, user is not authenticated
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        $userId = $auth->id();

        if ($userId != $videoJob->user_id) {
            // Handle error, user is not authenticated
            return response()->json(['error' => 'Unauthorized. Nacho video.'], 403);
        }

        $videoJob->resetProgress('cancelled');

        if ($videoJob) {
            return response()->json([
                'status' => $videoJob->status,
                'progress' => 0,
                'job_time' => 0,
                'estimated_time_left' => 0,
            ]);
        } else {
            return response()->json(['error' => 'Job not found'], 404);
        }
    }

    public function status($id)
    {
        $videoJob = Videojob::findOrFail($id);

        if ($videoJob) {
            return response()->json([
                'status' => $videoJob->status,
                'progress' => $videoJob->progress,
                'estimated_time_left' => $videoJob->estimated_time_left,
                'job_time' => $videoJob->job_time,
            ]);
        } else {
            return response()->json(['error' => 'Job not found'], 404);
        }
    }

    public function getVideoJobs($id)
    {
        // Validate the video file
        $user_id = auth()->id(); // This will get the ID of the currently authenticated user

        if (!$user_id) {
            // Handle error, user is not authenticated
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $videoJobs = VideoJob::where('user_id', $user_id)->get();

        return response()->json($videoJobs);
    }
}