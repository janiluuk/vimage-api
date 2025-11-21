<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->string('soundtrack_path')->nullable()->after('outfile');
            $table->string('soundtrack_url')->nullable()->after('soundtrack_path');
            $table->string('soundtrack_mimetype', 64)->nullable()->after('soundtrack_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['soundtrack_path', 'soundtrack_url', 'soundtrack_mimetype']);
        });
    }
};
