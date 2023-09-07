<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

class VideojobPreviewImageResource extends MediaResource
{
    /**
     * @param $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [];
        
        return [
            'id' => $this->resource->id,
            'file_name' => $this->resource->file_name,
            'parameters' => $this->resource->getCustomProperty('generation_parameters'),
            'generated_at' => $this->resource->getCustomProperty('generated_at'),
            'type' => $this->resource->getCustomProperty('type'),
            'href' => $this->resource->getCustomProperty('href'),
            'versions' => $this->versions(),
        ];
    }
}