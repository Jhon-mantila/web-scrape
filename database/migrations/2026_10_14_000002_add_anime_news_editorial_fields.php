<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->foreignId('merged_into_news_id')
                ->nullable()
                ->after('status_ia')
                ->constrained('news')
                ->nullOnDelete();
        });

        Schema::table('news_details', function (Blueprint $table) {
            $table->json('grouped_sources')->nullable()->after('fact_sheet');
        });
    }

    public function down(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->dropColumn('grouped_sources');
        });

        Schema::table('news', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_news_id');
        });
    }
};
