<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TODO: When calendar functionality is introduced, recurring schedules
 * should be moved from the `schedule` JSON column into a dedicated
 * `study_sessions` table with proper datetime fields, recurrence rules,
 * and foreign key back to `study_plans`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->string('subject')->after('id');
            $table->text('description')->nullable()->after('subject');
            $table->json('schedule')->nullable()->after('description');
            $table->string('status')->default('active')->after('schedule');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->date('target_date')->nullable()->after('started_at');
            $table->text('notes')->nullable()->after('target_date');
            $table->json('metadata')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->dropColumn([
                'subject',
                'description',
                'schedule',
                'status',
                'started_at',
                'target_date',
                'notes',
                'metadata',
            ]);
        });
    }
};
