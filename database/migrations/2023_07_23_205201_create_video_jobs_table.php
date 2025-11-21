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
        Schema::create('video_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id')->index('video_jobs_user_id_foreign');
            $table->unsignedBigInteger('model_id')->index('model_id');
            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('outfile')->nullable();
            $table->text('prompt')->nullable();
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->fulltext('prompt');
            }
            $table->integer('cfg_scale')->default(7);
            $table->enum('status', ['pending', 'processing', 'finished', 'error', 'preview', 'approved', 'cancelled'])->nullable();
            $table->string('url')->nullable();
            $table->string('preview_url')->nullable();
            $table->string('preview_img')->nullable();
            $table->bigInteger('seed')->nullable();
            $table->integer('steps')->nullable()->default(20);
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('fps')->nullable();
            $table->integer('length')->nullable();
            $table->string('codec', 128)->nullable();
            $table->integer('size')->nullable();
            $table->integer('frame_count')->nullable();
            $table->float('denoising', 10, 0)->nullable();
            $table->integer('progress')->default(0);
            $table->integer('job_time')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('estimated_time_left')->nullable();
            $table->integer('bitrate')->nullable();
            $table->string('audio_codec', 32)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('video_jobs');
    }
};
