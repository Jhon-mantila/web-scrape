<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_scheduler_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('social_queue_enabled')->default(true);
            $table->string('frequency', 32)->default('every_minute');
            $table->unsignedTinyInteger('interval_hours')->default(3);
            $table->string('daily_at', 5)->default('09:00');
            $table->timestamp('once_at')->nullable();
            $table->string('timezone', 64)->default('America/Bogota');
            $table->timestamp('last_social_queue_run_at')->nullable();
            $table->json('last_social_queue_summary')->nullable();
            $table->timestamp('scheduler_heartbeat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_scheduler_settings');
    }
};
