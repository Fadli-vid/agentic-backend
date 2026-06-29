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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('is_completed');
            $table->string('priority')->default('medium')->after('status');
            $table->text('description')->nullable()->after('priority');
            $table->timestamp('deadline_at')->nullable()->after('description');
            $table->timestamp('started_at')->nullable()->after('deadline_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');

            $table->index('status');
            $table->index('priority');
            $table->index('deadline_at');
            $table->index('created_at');
            $table->index('updated_at');
        });

        \Illuminate\Support\Facades\DB::table('tasks')->update([
            'status' => \Illuminate\Support\Facades\DB::raw("CASE WHEN is_completed = true THEN 'completed' ELSE 'pending' END")
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['deadline_at']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['updated_at']);

            $table->dropColumn([
                'status',
                'priority',
                'description',
                'deadline_at',
                'started_at',
                'completed_at',
            ]);
        });
    }
};
