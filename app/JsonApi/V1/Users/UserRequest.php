<?php

namespace App\JsonApi\V1\Users;

use LaravelJsonApi\Validation\Rules\HasMany;

use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Spec\Validators\RelationshipsValidator;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

class UserRequest extends ResourceRequest
{

    /**
     * Get the validation rules for the resource.
     *
     * @return array
     */
    protected function rules(): array
    {
        if ($model = $this->model()) {
            return [
                'name' => 'sometimes|string',
                'email' => ['sometimes', 'email', Rule::unique('users')->ignore($this->model->id)],
                'profile_image' => 'sometimes|nullable|url',
                'password' => 'sometimes|confirmed|string|min:8',
                'roles' => ['sometimes','exists:roles']
            ];
        }

        return [
            'name' => 'required|string',
            'email' => ['required', 'email', Rule::unique('users')],
            'profile_image' => 'nullable|url',
            'password' => 'required|confirmed|string|min:8',
            'roles' => [
                'required',
                'exists:roles'
            ]
        ];
       
    }


}
