<?php

namespace App\JsonApi\V1\Tags;

use Illuminate\Http\Request;
use LaravelJsonApi\Core\Resources\JsonApiResource;

class TagResource extends JsonApiResource
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
            'color' => $this->color,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
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
            $this->relation('items'),
        ];

    }

}
