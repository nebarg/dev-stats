<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('entity', 1024);
            $table->string('entity_type', 32);
            $table->string('category', 64)->nullable();
            $table->string('project')->nullable();
            $table->string('branch')->nullable();
            $table->string('language', 64)->nullable();
            $table->json('dependencies')->nullable();
            $table->boolean('is_write')->nullable();
            $table->unsignedInteger('line_count')->nullable();
            $table->unsignedInteger('line_number')->nullable();
            $table->unsignedInteger('cursor_position')->nullable();
            $table->unsignedInteger('project_root_count')->nullable();

            $table->string('editor', 64)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('machine')->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->integer('ai_line_changes')->nullable();
            $table->integer('human_line_changes')->nullable();
            $table->string('ai_session')->nullable();
            $table->string('ai_subscription_plan')->nullable();
            $table->unsignedBigInteger('ai_input_tokens')->nullable();
            $table->unsignedBigInteger('ai_output_tokens')->nullable();
            $table->unsignedInteger('ai_prompt_length')->nullable();

            $table->dateTime('recorded_at', precision: 3);
            $table->string('hash', 64)->unique();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
