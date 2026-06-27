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
        Schema::table('memories', function (Blueprint $table) {
            $table->string('category')->nullable()->after('id');
            $table->string('title')->after('category');
            $table->text('content')->after('title');
            $table->string('source')->nullable()->after('content');
            $table->integer('importance')->default(5)->after('source');
            $table->json('metadata')->nullable()->after('importance');
            $table->timestamp('last_accessed_at')->nullable()->after('metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'title',
                'content',
                'source',
                'importance',
                'metadata',
                'last_accessed_at',
            ]);
        });
    }
};
