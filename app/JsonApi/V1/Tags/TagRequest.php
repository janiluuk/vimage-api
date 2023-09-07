<?php

namespace App\JsonApi\V1\Tags;

use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

class TagRequest extends ResourceRequest
{

    /**
     * Get the validation rules for the resource.
     *
     * @return array
     */
    public function rules(): array
    {
        if ($model = $this->model()) {
            return [
                'name' => [
                    Rule::unique('tags')->ignore($this->model->id),
                    'sometimes',
                    'string',
                ],
                'color' => 'sometimes|string'
            ];
        }

        return [
            'name' => 'required|string|unique:tags,name',
            'color' => 'required|string'
        ];
    }

}
