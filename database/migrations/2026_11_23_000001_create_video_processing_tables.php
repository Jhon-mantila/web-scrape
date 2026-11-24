<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('capabilities')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('current_job_id')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('video_processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_video_id')->constrained('social_videos')->cascadeOnDelete();
            $table->string('type', 32)->default('intelligent_split');
            $table->string('status', 32)->default('queued');
            $table->json('params');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('progress_message')->nullable();
            $table->foreignId('worker_id')->nullable()->constrained('video_workers')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('idempotency_key', 64)->unique();
            $table->string('analysis_path')->nullable();
            $table->text('error_message')->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['social_video_id', 'status']);
        });

        Schema::table('social_videos', function (Blueprint $table) {
            $table->foreignId('parent_video_id')
                ->nullable()
                ->after('user_id')
                ->constrained('social_videos')
                ->nullOnDelete();
            $table->string('kind', 16)->default('original')->after('parent_video_id');
            $table->foreignId('source_job_id')
                ->nullable()
                ->after('kind')
                ->constrained('video_processing_jobs')
                ->nullOnDelete();
            $table->unsignedSmallInteger('clip_index')->nullable()->after('source_job_id');
            $table->unsignedInteger('starts_at_ms')->nullable()->after('clip_index');
            $table->unsignedInteger('ends_at_ms')->nullable()->after('starts_at_ms');

            $table->unique(['parent_video_id', 'clip_index']);
            $table->index('kind');
        });

        Schema::create('video_processing_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('video_processing_jobs')->cascadeOnDelete();
            $table->unsignedSmallInteger('index');
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->string('source', 16)->default('smart');
            $table->decimal('score', 5, 2)->nullable();
            $table->json('warnings')->nullable();
            $table->boolean('approved')->default(false);
            $table->foreignId('output_video_id')->nullable()->constrained('social_videos')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_id', 'index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_processing_segments');

        Schema::table('social_videos', function (Blueprint $table) {
            $table->dropUnique(['parent_video_id', 'clip_index']);
            $table->dropConstrainedForeignId('source_job_id');
            $table->dropConstrainedForeignId('parent_video_id');
            $table->dropColumn(['kind', 'clip_index', 'starts_at_ms', 'ends_at_ms']);
        });

        Schema::dropIfExists('video_processing_jobs');
        Schema::dropIfExists('video_workers');
    }
};
