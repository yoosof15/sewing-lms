<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });
        Schema::table('lessons', fn (Blueprint $table) => $table->timestamp('release_notification_dispatched_at')->nullable()->index()->after('available_at'));
    }

    public function down(): void
    {
        Schema::table('lessons', fn (Blueprint $table) => $table->dropColumn('release_notification_dispatched_at'));
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
