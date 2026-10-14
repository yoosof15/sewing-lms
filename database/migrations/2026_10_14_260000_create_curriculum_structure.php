<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['course_id', 'sort_order']);
            $table->index(['course_id', 'deleted_at']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('navigation_mode', 20)->default('FREE')->index()->after('status');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('course_id')->constrained('course_sections')->nullOnDelete();
            $table->timestamp('available_at')->nullable()->index()->after('published_at');
            $table->index(['course_id', 'section_id', 'sort_order']);
        });

        Schema::create('lesson_prerequisites', function (Blueprint $table) {
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['lesson_id', 'prerequisite_lesson_id']);
            $table->index('prerequisite_lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_prerequisites');
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropIndex(['course_id', 'section_id', 'sort_order']);
            $table->dropColumn(['section_id', 'available_at']);
        });
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('navigation_mode'));
        Schema::dropIfExists('course_sections');
    }
};
