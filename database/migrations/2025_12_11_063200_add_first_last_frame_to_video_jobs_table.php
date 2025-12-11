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
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->string('first_frame_path')->nullable()->after('url');
            $table->string('last_frame_path')->nullable()->after('first_frame_path');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['first_frame_path', 'last_frame_path']);
        });
    }
};
