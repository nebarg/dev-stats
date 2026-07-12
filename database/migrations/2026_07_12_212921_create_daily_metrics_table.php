<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('day');
            $table->string('project')->nullable();
            $table->string('editor', 64)->nullable();

            // Net line changes are signed: deletions can push them negative.
            $table->integer('ai_lines')->default(0);
            $table->integer('human_lines')->default(0);

            $table->unsignedBigInteger('ai_input_tokens')->default(0);
            $table->unsignedBigInteger('ai_output_tokens')->default(0);
            $table->unsignedInteger('ai_prompts')->default(0);
            $table->unsignedBigInteger('ai_prompt_length')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
    }
};
