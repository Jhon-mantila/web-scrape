<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_posts', function (Blueprint $table) {
            $table->id();
            $table->string('site', 64);
            $table->unsignedBigInteger('wp_post_id');
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->string('url');
            $table->string('featured_image_url')->nullable();
            $table->timestamp('published_at_wp')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['site', 'wp_post_id']);
            $table->index(['site', 'published_at_wp']);
        });

        Schema::create('social_article_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 64);
            $table->string('status', 32)->default('draft');
            $table->text('message')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();
            $table->json('api_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['wordpress_post_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_article_publications');
        Schema::dropIfExists('wordpress_posts');
    }
};
