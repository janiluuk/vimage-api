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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'online')) {
                $table->integer('online')->default(0)->after('profile_image');
            }
            if (!Schema::hasColumn('users', 'confirm_send_email')) {
                $table->integer('confirm_send_email')->default(1)->after('online');
            }
            if (!Schema::hasColumn('users', 'password_reset_admin')) {
                $table->boolean('password_reset_admin')->default(false)->after('confirm_send_email');
            }
            if (!Schema::hasColumn('users', 'balance')) {
                $table->decimal('balance', 10, 2)->default(0)->after('password_reset_admin');
            }
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->after('balance');
            }
            if (!Schema::hasColumn('users', 'discord_id')) {
                $table->string('discord_id')->nullable()->after('google_id');
            }
            if (!Schema::hasColumn('users', 'stripe_id')) {
                $table->string('stripe_id')->nullable()->after('discord_id');
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
        Schema::table('users', function (Blueprint $table) {
            $columns = ['online', 'confirm_send_email', 'password_reset_admin', 'balance', 'google_id', 'discord_id', 'stripe_id'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
