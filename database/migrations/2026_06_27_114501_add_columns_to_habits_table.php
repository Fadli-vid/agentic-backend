<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->text('description')->nullable()->after('name');
            $table->string('frequency')->default('daily')->after('description');
            $table->integer('target_count')->default(1)->after('frequency');
            $table->integer('current_streak')->default(0)->after('target_count');
            $table->integer('longest_streak')->default(0)->after('current_streak');
            $table->timestamp('last_completed_at')->nullable()->after('longest_streak');
            $table->boolean('is_active')->default(true)->after('last_completed_at');
            $table->json('metadata')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'description',
                'frequency',
                'target_count',
                'current_streak',
                'longest_streak',
                'last_completed_at',
                'is_active',
                'metadata',
            ]);
        });
    }
};
