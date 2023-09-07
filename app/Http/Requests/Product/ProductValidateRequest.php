<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\ApiFormRequest;

class ProductValidateRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'string|min:2|max:200|nullable',
            'description' => 'string|min:2|max:500|nullable',
            'categoryId' => 'required|integer|exists:categories,id',
            'price' => 'required|numeric',
            'old_price' => 'numeric|nullable',
            'quantity' => 'required|numeric',
            'status' => 'string|nullable',
            'vendor_code' => 'string|nullable',
            'shortDescription' =>'string|min:2|max:150|nullable',
            'warningDescription' => 'string|min:2|max:150|nullable',
            'options' =>'string|min:2|max:500|nullable',
            'slug' => 'string|min:2|max:64|nullable',
        ];
    }
}
