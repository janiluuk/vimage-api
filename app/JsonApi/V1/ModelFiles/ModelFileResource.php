<?php

namespace App\JsonApi\V1\ModelFiles;

use Illuminate\Http\Request;
use LaravelJsonApi\Core\Resources\JsonApiResource;

class ModelFileResource extends JsonApiResource
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
            'name' => $this->name,
            'filename' => $this->filename,
            'enabled' => $this->enabled,
            'previewUrl' => $this->preview_url,
            'sample_prompts' => $this->sample_prompts,
            'nsfw' => $this->nsfw,            
            'modelType' => $this->model_type,
            'description' => $this->description,
            'version' => $this->version,           
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
            $this->relation('videoJobs'),
        ];
    }

}
