<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->string('original_filename');
            $table->string('status')->index();
            foreach (['total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'created_users', 'matched_existing_users', 'created_enrollments', 'failed_rows'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
            $table->json('options');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('student_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('student_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('data');
            $table->string('status')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('error_codes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_import_rows');
        Schema::dropIfExists('student_import_batches');
    }
};
