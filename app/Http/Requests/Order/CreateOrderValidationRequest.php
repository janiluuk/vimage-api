<?php

namespace App\Http\Requests\Order;

use App\Constant\OrderPaymentConstant;
use App\Constant\OrderStatusConstant;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Http\Requests\ApiFormRequest;

class CreateOrderValidationRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        /** @var User $user */
        $user = $this->user();

        $this->merge([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => OrderStatusConstant::UNPAID,
        ]);
    }

    public function rules(): array
    {
            return [
                'uuid' => ['required', 'uuid'],
                'user_id' => ['required', Rule::exists('users', 'id')],
                'promo_code_id' => ['nullable', Rule::exists('promo_codes', 'id')],
                'payment_method' => ['required', Rule::in(OrderPaymentConstant::getValues())],
                'status' => ['required', Rule::in(OrderStatusConstant::getValues())],
                'products_cost' => ['required', 'numeric'],
                'delivery_cost' => ['nullable', 'numeric'],
                'total_cost' => ['required', 'numeric'],
                'items' => ['required', 'array'],
                'items.*.good_id' => ['required', Rule::exists('goods', 'id')],
                'items.*.quantity' => ['required', 'integer', 'gte:1'],
            ];
    }
    public function authorize(): bool
    {
        return boolval($this->user());
    }
}
