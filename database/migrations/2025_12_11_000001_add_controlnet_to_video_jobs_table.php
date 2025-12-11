<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('video_jobs', 'controlnet')) {
                $table->text('controlnet')->nullable()->after('negative_prompt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('video_jobs', 'controlnet')) {
                $table->dropColumn('controlnet');
            }
        });
    }
};
