<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPayment extends Model
{
    protected $fillable = ['order_id', 'amount', 'status', 'type', 'session_id'];

    public static array $types = [
        'Pay now',
        'PrivatPay',
        'Stripe',
        'Credit card',
    ];
}