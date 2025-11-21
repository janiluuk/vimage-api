<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('model_files')) {
            return;
        }

        Schema::create('model_files', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('version')->nullable();
            $table->string('previewUrl')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_files');
    }
};
