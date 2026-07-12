<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prices', function (Blueprint $table) {
            $table->id();

            $table->string('model_prefix', 100);
            $table->decimal('input_price', 8, 4);
            $table->decimal('output_price', 8, 4);
            $table->date('effective_from');

            $table->timestamps();

            $table->unique(['model_prefix', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prices');
    }
};
