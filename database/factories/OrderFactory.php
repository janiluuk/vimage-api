<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use App\Constant\OrderPaymentConstant;
use App\Constant\OrderStatusConstant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $productCost = $this->faker->randomFloat(2, 10, 1000);
        $deliveryCost = $this->faker->randomFloat(2, 0, 50);
        
        return [
            'uuid' => Str::uuid(),
            'user_id' => User::factory(),
            'promo_code_id' => null,
            'payment_method' => OrderPaymentConstant::STRIPE,
            'product_cost' => $productCost,
            'delivery_cost' => $deliveryCost,
            'total_cost' => $productCost + $deliveryCost,
            'status' => OrderStatusConstant::UNPAID,
            'status_history' => null,
        ];
    }
}
