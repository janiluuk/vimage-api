<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\Conversions\Conversion;
use Illuminate\Http\Request;
use LaravelJsonApi\Core\Resources\JsonApiResource;

abstract class MediaResource extends JsonApiResource
{
    /**
     * The resource instance.
     *
     * @var Media
     */
    public $resource;

    /**
     * @var array
     */
    protected $filterVersions = [];

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'file_name' => $this->resource->file_name,
            'href' => $this->resource->getCustomProperty('href'),
            'versions' => $this->versions(),
        ];
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Http\JsonResponse $response
     */
    public function withResponse($request, $response)
    {
        $response->setStatusCode(200);
    }

    /**
     * @param $versions
     * @return MediaResource
     */
    public function filterVersions($versions)
    {
        $this->filterVersions = $versions;

        return $this;
    }

    /**
     * @return mixed
     */
    protected function versions()
    {
        $conversions = $this->resource->getScopedConversions()
            ->reduce(function ($carry, Conversion $conversion) {
                if ($this->filterVersions && ! in_array($conversion->getName(), $this->filterVersions)) {
                    return $carry;
                }

                $conversionName = $conversion->getName();
                $carry[$conversionName] = $this->resource->getConversionUrl($conversion);

                return $carry;
            }, []);

        return array_merge([
            'original' => $this->resource->getFullUrl()
        ], $conversions);
    }
}