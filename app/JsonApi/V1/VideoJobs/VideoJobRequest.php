<?php

namespace App\JsonApi\V1\VideoJobs;

use Illuminate\Foundation\Http\FormRequest;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

class VideoJobRequest extends ResourceRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'user' => JsonApiRule::toOne(),
            'modelfile' => JsonApiRule::toOne(),
        ];
    }
}
