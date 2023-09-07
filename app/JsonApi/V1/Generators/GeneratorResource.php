<?php

namespace App\JsonApi\V1\Generators;

use Illuminate\Http\Request;
use LaravelJsonApi\Core\Resources\JsonApiResource;

class GeneratorResource extends JsonApiResource
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
            'identifier' => $this->identifier,
	    'type' => $this->identifier,
	    'description' => $this->description,
	    'created_at' => $this->created_at,
        ];

    }

    /**
     * Get the resource's relationships.
     *
     * @param Request|null $request
     * @return iterable
     */

}
