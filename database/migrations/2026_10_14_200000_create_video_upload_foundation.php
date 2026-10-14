<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('original_filename');
            $table->string('storage_driver', 50);
            $table->string('storage_key')->unique();
            $table->string('original_mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('sort_order');
            $table->string('status', 20)->index();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['lesson_id', 'sort_order']);
            $table->index(['lesson_id', 'status', 'sort_order']);
            $table->index('uploaded_by');
        });
        Schema::create('video_upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_video_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('original_filename');
            $table->string('declared_mime_type', 120)->nullable();
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->string('temporary_key')->unique();
            $table->string('status', 20)->index();
            $table->text('failure_reason')->nullable();
            // DATETIME avoids MariaDB's implicit ON UPDATE behavior for the
            // first non-null TIMESTAMP column on older XAMPP configurations.
            $table->dateTime('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_upload_sessions');
        Schema::dropIfExists('lesson_videos');
    }
};
