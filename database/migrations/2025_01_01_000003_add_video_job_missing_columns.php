<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('video_jobs', 'negative_prompt')) {
                $table->text('negative_prompt')->nullable();
            }
            if (! Schema::hasColumn('video_jobs', 'generator')) {
                $table->string('generator')->nullable();
            }
            if (! Schema::hasColumn('video_jobs', 'queued_at')) {
                $table->timestamp('queued_at')->nullable();
            }
            if (! Schema::hasColumn('video_jobs', 'retries')) {
                $table->integer('retries')->default(0);
            }
            if (! Schema::hasColumn('video_jobs', 'mimetype')) {
                $table->string('mimetype')->nullable();
            }
            if (! Schema::hasColumn('video_jobs', 'original_url')) {
                $table->string('original_url')->nullable();
            }
            if (! Schema::hasColumn('video_jobs', 'thumbnail')) {
                $table->string('thumbnail')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'negative_prompt',
                'generator',
                'queued_at',
                'retries',
                'mimetype',
                'original_url',
                'thumbnail',
            ]);
        });
    }
};
