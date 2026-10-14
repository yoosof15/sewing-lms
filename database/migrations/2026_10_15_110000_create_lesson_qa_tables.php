<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $t->string('subject', 180)->nullable();
            $t->text('body');
            $t->string('status', 20)->index();
            $t->timestamp('resolved_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_activity_at')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['lesson_id', 'student_id']);
        });
        Schema::create('lesson_question_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('question_id')->constrained('lesson_questions')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->boolean('is_staff')->default(false)->index();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('lesson_question_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('question_id')->constrained('lesson_questions')->cascadeOnDelete();
            $t->foreignId('reply_id')->nullable()->constrained('lesson_question_replies')->cascadeOnDelete();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('original_name');
            $t->string('storage_path');
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('file_size');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_question_attachments');
        Schema::dropIfExists('lesson_question_replies');
        Schema::dropIfExists('lesson_questions');
    }
};
