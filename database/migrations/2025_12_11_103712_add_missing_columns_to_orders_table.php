<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // Make old columns nullable that conflict with new schema
            if (Schema::hasColumn('orders', 'product_id')) {
                $table->foreignId('product_id')->nullable()->change();
            }
            if (Schema::hasColumn('orders', 'user_customer_id')) {
                $table->foreignId('user_customer_id')->nullable()->change();
            }
            if (Schema::hasColumn('orders', 'user_seller_id')) {
                $table->foreignId('user_seller_id')->nullable()->change();
            }
            if (Schema::hasColumn('orders', 'wallet_type_id')) {
                $table->foreignId('wallet_type_id')->nullable()->change();
            }
            if (Schema::hasColumn('orders', 'ship_type')) {
                $table->integer('ship_type')->nullable()->change();
            }
            if (Schema::hasColumn('orders', 'order_price')) {
                $table->float('order_price')->nullable()->change();
            }
            if (Schema::hasColumn('orders', 'quantity')) {
                $table->integer('quantity')->nullable()->change();
            }
            if (Schema::hasColumn('orders', 'date_open')) {
                $table->dateTime('date_open')->nullable()->change();
            }
            
            // Add user_id if it doesn't exist (old migration used user_customer_id)
            if (!Schema::hasColumn('orders', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('uuid');
            }
            
            // Add promo_code_id if it doesn't exist
            if (!Schema::hasColumn('orders', 'promo_code_id')) {
                $table->foreignId('promo_code_id')->nullable()->after('user_id');
            }
            
            // Add payment_method if it doesn't exist
            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('promo_code_id');
            }
            
            // Add product_cost if it doesn't exist
            if (!Schema::hasColumn('orders', 'product_cost')) {
                $table->unsignedDecimal('product_cost', 10, 2)->nullable()->after('payment_method');
            }
            
            // Add delivery_cost if it doesn't exist
            if (!Schema::hasColumn('orders', 'delivery_cost')) {
                $table->unsignedDecimal('delivery_cost', 10, 2)->nullable()->default(0)->after('product_cost');
            }
            
            // Add total_cost if it doesn't exist
            if (!Schema::hasColumn('orders', 'total_cost')) {
                $table->unsignedDecimal('total_cost', 10, 2)->nullable()->after('delivery_cost');
            }
            
            // Modify status column if needed (old migration uses integer)
            if (Schema::hasColumn('orders', 'status')) {
                // Change status from integer to string if it's integer
                $table->string('status')->nullable()->change();
            }
            
            // Add status_history if it doesn't exist
            if (!Schema::hasColumn('orders', 'status_history')) {
                $table->json('status_history')->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = ['user_id', 'promo_code_id', 'payment_method', 'product_cost', 'delivery_cost', 'total_cost', 'status_history'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
