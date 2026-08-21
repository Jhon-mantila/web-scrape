<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->string('featured_image_path')->nullable()->after('content_text');
        });

        Schema::table('news_ai_articles', function (Blueprint $table) {
            $table->string('article_type')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->dropColumn('featured_image_path');
        });

        Schema::table('news_ai_articles', function (Blueprint $table) {
            $table->dropColumn('article_type');
        });
    }
};
