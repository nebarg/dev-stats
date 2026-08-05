<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('api_key')->nullable()->unique()->after('email');
            $table->string('timezone')->default('UTC')->after('api_key');
            $table->unsignedTinyInteger('start_of_week')->default(1)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_key', 'timezone', 'start_of_week']);
        });
    }
};
