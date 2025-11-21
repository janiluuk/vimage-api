
<?php
use App\Constant\OrderPaymentConstant;
use App\Constant\OrderStatusConstant;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            return;
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique()->index();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignIdFor(PromoCode::class)->nullable()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->enum('payment_method',OrderPaymentConstant::getValues());
            $table->unsignedDecimal('product_cost');
            $table->unsignedDecimal('delivery_cost')->nullable()->default(0);
            $table->unsignedDecimal('total_cost');
            $table->enum('status', OrderStatusConstant::getValues())->default(OrderStatusConstant::UNPAID);
            $table->json('status_history')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};