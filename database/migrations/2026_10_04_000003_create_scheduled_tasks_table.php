<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_key', 64)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('frequency', 32)->default('every_minute');
            $table->unsignedTinyInteger('interval_hours')->default(3);
            $table->string('daily_at', 5)->default('09:00');
            $table->timestamp('once_at')->nullable();
            $table->string('timezone', 64)->default('America/Bogota');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_run_summary')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('app_scheduler_settings')) {
            $legacy = DB::table('app_scheduler_settings')->first();

            if ($legacy !== null) {
                DB::table('scheduled_tasks')->insert([
                    'task_key' => 'social_videos_publish_queue',
                    'label' => 'Cola de videos (redes sociales)',
                    'description' => 'Publica videos con fecha «Enviar automáticamente (app)» en Videos.',
                    'enabled' => (bool) $legacy->social_queue_enabled,
                    'frequency' => $legacy->frequency,
                    'interval_hours' => $legacy->interval_hours,
                    'daily_at' => $legacy->daily_at,
                    'once_at' => $legacy->once_at,
                    'timezone' => $legacy->timezone,
                    'sort_order' => 10,
                    'last_run_at' => $legacy->last_social_queue_run_at,
                    'last_run_summary' => $legacy->last_social_queue_summary,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::drop('app_scheduler_settings');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
