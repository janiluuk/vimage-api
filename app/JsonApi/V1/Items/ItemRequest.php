<?php

namespace App\JsonApi\V1\Items;

use App\Helpers\Enum;
use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

class ItemRequest extends ResourceRequest
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
                'name' => 'sometimes|string',
                'status' => ['sometimes', Rule::in(Enum::getValues('items', 'status'))],
                'excerpt' => 'sometimes|string',
                'description' => 'sometimes|string|nullable',
                'image' => 'sometimes|nullable|url',
                'is_on_homepage' => 'sometimes|boolean',
                'date_at' => 'sometimes|date_format:Y-m-d'
            ];
        }

        return [
            'name' => 'required|string',
            'status' => ['required', Rule::in(Enum::getValues('items', 'status'))],
            'excerpt' => 'required|string',
            'description' => 'string|nullable',
            'image' => 'nullable|url',
            'is_on_homepage' => 'required|boolean',
            'date_at' => 'required|date_format:Y-m-d'
        ];

    }

}
