<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->string('title', 180);
            $t->text('description')->nullable();
            $t->string('status', 20)->index();
            $t->timestamp('available_at')->nullable()->index();
            $t->timestamp('due_at')->nullable()->index();
            $t->boolean('allow_late_submission')->default(false);
            $t->boolean('allow_resubmission')->default(false);
            $t->unsignedSmallInteger('max_attempts')->nullable();
            $t->timestamp('availability_notification_dispatched_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['lesson_id', 'status']);
        });
        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained('lesson_assignments')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedSmallInteger('attempt_number');
            $t->text('text_content')->nullable();
            $t->string('status', 30)->index();
            $t->boolean('is_late')->default(false);
            $t->timestamp('submitted_at')->index();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('reviewer_feedback')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['assignment_id', 'student_id', 'attempt_number'], 'assignment_student_attempt_unique');
            $t->index(['assignment_id', 'student_id']);
        });
        Schema::create('submission_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained('assignment_submissions')->cascadeOnDelete();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('original_name');
            $t->string('storage_path');
            $t->string('mime_type', 120);
            $t->unsignedBigInteger('file_size');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_files');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('lesson_assignments');
    }
};
