<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('video_path');
            $table->string('thumbnail_path');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('social_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_video_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 64);
            $table->string('status', 32)->default('draft');
            $table->text('caption_generated')->nullable();
            $table->text('caption_edited')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();
            $table->json('api_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['social_video_id', 'platform']);
        });

        Schema::create('social_platform_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 64)->unique();
            $table->string('label');
            $table->text('credentials')->nullable();
            $table->boolean('is_connected')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_platform_accounts');
        Schema::dropIfExists('social_publications');
        Schema::dropIfExists('social_videos');
    }
};
