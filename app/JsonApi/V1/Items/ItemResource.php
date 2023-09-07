<?php

namespace App\JsonApi\V1\Items;

use Illuminate\Http\Request;
use LaravelJsonApi\Core\Resources\JsonApiResource;

class ItemResource extends JsonApiResource
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
            'status' => $this->status,
            'excerpt' => $this->excerpt,
            'description' => $this->description,
            'image' => $this->image,
            'isOnHomepage' => $this->is_on_homepage,
            'dateAt' => $this->date_at,
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
            $this->relation('category'),
            $this->relation('user'),
            $this->relation('tags'),
        ];
    }

}
