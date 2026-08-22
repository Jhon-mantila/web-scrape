<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_ai_articles', function (Blueprint $table) {
            $table->longText('raw_ai_response')->nullable()->after('body_html');
        });
    }

    public function down(): void
    {
        Schema::table('news_ai_articles', function (Blueprint $table) {
            $table->dropColumn('raw_ai_response');
        });
    }
};
