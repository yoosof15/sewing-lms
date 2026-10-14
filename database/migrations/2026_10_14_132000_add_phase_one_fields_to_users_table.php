<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('mobile', 13)->nullable()->unique()->after('last_name');
            $table->boolean('is_active')->default(true)->index()->after('password');
        });
        DB::table('users')->whereNull('first_name')->update(['first_name' => DB::raw('name')]);
        Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->dropUnique(['mobile']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['first_name', 'last_name', 'mobile', 'is_active']);
        });
    }
};
