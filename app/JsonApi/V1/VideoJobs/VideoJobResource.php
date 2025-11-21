<?php

namespace App\JsonApi\V1\VideoJobs;

use App\Http\Resources\VideojobFinishedResource;
use App\Http\Resources\VideojobPreviewImageResource;
use App\JsonApi\V1\VideoJobs\VideojobPreviewImageSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LaravelJsonApi\Core\Resources\JsonApiResource;

class VideoJobResource extends JsonApiResource
{

    /**
     * Get the resource's attributes.
     *
     * @param Request|null $request
     * @return iterable
     */
    public function attributes($request): iterable
    {

        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'original_filename' => $this->original_filename,
            'original_url' => $this->original_url,
            'url' => $this->url,
            'status' => $this->status,
            'fps' => (int)$this->fps,
            'frame_count' =>  (int)$this->frame_count,
            'size' => (int)$this->size,
            'bitrate' => (int)$this->bitrate,
            'length' => (int)$this->length,
            'revision' => (string)$this->revision,
            'media' => [
                'finished' =>$this->getMediaFilesForRevision('video', 'finished'),
                'preview' => $this->getMediaFilesForRevision('image'),
                'animation' => $this->getMediaFilesForRevision('animation'),
                'original' => $this->getMediaFilesForRevision('video', 'original')
            ],
            'queue' => $this->getQueueInfo(),
            'revisions' => $this->getRevisions(),
            'progress' => $this->progress,
            'thumbnail' => $this->thumbnail,
            'preview_url' => $this->preview_url,
            'preview_img' => $this->preview_img,    
            'preview_animation' => $this->preview_animation,            
            'mimetype' => $this->mimetype,
            'generator' => $this->generator,
            'generation_parameters' => $this->generation_parameters,
            'job_time' => $this->job_time,
            'estimated_time_left' => $this->estimated_time_left,
            'codec' => $this->codec,
            'audio_codec' => $this->audio_codec,
            'soundtrack_url' => $this->soundtrack_url,
            'soundtrack_mimetype' => $this->soundtrack_mimetype,
            'width' => $this->width,
            'height' => $this->height,
            'model_id' => $this->model_id,
            'cfg_scale' => $this->cfg_scale,
            'steps' => $this->steps,
            'denoising' => $this->denoising,
            'user_id' => $this->user_id,
            'prompt' => $this->prompt,
            'negative_prompt' => $this->negative_prompt,
            'controlnet' => $this->controlnet,
            'seed' => $this->seed,
            'created_at' => (string) $this->created_at,
            'updated_at' => (string) $this->updated_at,            
        ];
    }

    /**
     * Get the resource's relationships.
     *
     * @param Request|null $request
     * @return iterable
     */
    public function relationships($request): iterable
    {   
        
        return [
            $this->relation('user'),
            $this->relation('modelfile'),
            $this->relation('media')            
        ];
    }

}
