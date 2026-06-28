<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('durations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->dateTime('started_at', precision: 3);
            $table->unsignedInteger('duration_seconds')->default(0);

            $table->string('project')->nullable();
            $table->string('language', 64)->nullable();
            $table->string('editor', 64)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('machine')->nullable();
            $table->string('branch')->nullable();
            $table->string('category', 64)->nullable();

            $table->unsignedInteger('heartbeat_count')->default(1);
            $table->string('group_hash', 64);
            $table->unsignedInteger('timeout_seconds');

            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('durations');
    }
};
