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
        Schema::create('agent_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source')->nullable();
            $table->string('user_name')->nullable();
            $table->string('chat_id')->nullable();
            $table->text('message')->nullable();
            $table->string('action')->nullable();
            $table->string('status')->default('received');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_events');
    }
};
