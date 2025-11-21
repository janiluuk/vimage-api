<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('video_jobs', 'generation_parameters')) {
                $table->json('generation_parameters')->nullable()->after('denoising');
            }

            if (! Schema::hasColumn('video_jobs', 'revision')) {
                $table->string('revision')->nullable()->after('generation_parameters');
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('video_jobs', 'revision')) {
                $table->dropColumn('revision');
            }

            if (Schema::hasColumn('video_jobs', 'generation_parameters')) {
                $table->dropColumn('generation_parameters');
            }
        });
    }
};
