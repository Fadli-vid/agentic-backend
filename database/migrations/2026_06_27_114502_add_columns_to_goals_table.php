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
        Schema::table('goals', function (Blueprint $table) {
            $table->string('title')->after('id');
            $table->text('description')->nullable()->after('title');
            $table->decimal('target_value', 16, 2)->nullable()->after('description');
            $table->decimal('current_value', 16, 2)->default(0)->after('target_value');
            $table->string('unit')->nullable()->after('current_value');
            $table->string('status')->default('active')->after('unit');
            $table->string('priority')->default('medium')->after('status');
            $table->date('due_date')->nullable()->after('priority');
            $table->timestamp('completed_at')->nullable()->after('due_date');
            $table->json('metadata')->nullable()->after('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'description',
                'target_value',
                'current_value',
                'unit',
                'status',
                'priority',
                'due_date',
                'completed_at',
                'metadata',
            ]);
        });
    }
};
