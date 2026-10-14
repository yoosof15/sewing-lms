<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedTinyInteger('completion_threshold_percent')->default(80)->after('status');
        });
        Schema::create('video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_video_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'lesson_video_id']);
            $table->index(['user_id', 'completed_at']);
        });
        Schema::create('lesson_manual_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->unique(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_manual_completions');
        Schema::dropIfExists('video_progress');
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('completion_threshold_percent'));
    }
};
