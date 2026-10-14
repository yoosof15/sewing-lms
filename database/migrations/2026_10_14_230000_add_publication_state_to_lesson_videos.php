<?php

use App\Enums\VideoPublicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_videos', function (Blueprint $table) {
            $table->string('publication_status', 20)->default(VideoPublicationStatus::Draft->value)->index()->after('status');
            $table->timestamp('published_at')->nullable()->after('publication_status');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_videos', function (Blueprint $table) {
            $table->dropIndex(['publication_status']);
            $table->dropColumn(['publication_status', 'published_at']);
        });
    }
};
