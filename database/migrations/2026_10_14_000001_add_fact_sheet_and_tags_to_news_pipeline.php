<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->json('fact_sheet')->nullable()->after('research_raw');
        });

        Schema::table('news_ai_articles', function (Blueprint $table) {
            $table->json('generated_tags')->nullable()->after('article_type');
        });
    }

    public function down(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->dropColumn('fact_sheet');
        });

        Schema::table('news_ai_articles', function (Blueprint $table) {
            $table->dropColumn('generated_tags');
        });
    }
};
