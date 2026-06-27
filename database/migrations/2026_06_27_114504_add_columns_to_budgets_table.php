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
        Schema::table('budgets', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->decimal('amount', 16, 2)->after('name');
            $table->string('period')->default('monthly')->after('amount');
            $table->string('category')->nullable()->after('period');
            $table->date('start_date')->nullable()->after('category');
            $table->date('end_date')->nullable()->after('start_date');
            $table->boolean('is_active')->default(true)->after('end_date');
            $table->json('metadata')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'amount',
                'period',
                'category',
                'start_date',
                'end_date',
                'is_active',
                'metadata',
            ]);
        });
    }
};
