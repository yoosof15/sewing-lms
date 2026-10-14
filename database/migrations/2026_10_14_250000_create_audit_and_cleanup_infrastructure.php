<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 160)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('lesson_attachments', fn (Blueprint $table) => $table->softDeletes());

        Schema::create('storage_cleanup_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type', 40);
            $table->unsignedBigInteger('resource_id');
            $table->string('status', 20)->default('PENDING')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_cleanup_tasks');
        Schema::table('lesson_attachments', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::dropIfExists('audit_logs');
    }
};
