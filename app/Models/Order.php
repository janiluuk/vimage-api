<?php
namespace App\Models;

use App\Constant\OrderStatusConstant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'user_address_id',
        'promo_code_id',
        'payment_method',
        'products_cost',
        'delivery_cost',
        'total_cost',
        'status',
        'status_history',
    ];

    protected $casts = [
        'payment_method' => \App\Constant\OrderPaymentConstant::class,
        'goods_cost' => 'float',
        'delivery_cost' => 'float',
        'total_cost' => 'float',
        'status' => OrderStatusConstant::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}