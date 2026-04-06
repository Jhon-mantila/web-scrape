<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('news_ai_articles', function (Blueprint $table) {
            //
            $table->boolean('sent_wordpress')->default(false)->after('model');
            $table->timestamp('sent_wordpress_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news_ai_articles', function (Blueprint $table) {
            //
            $table->dropColumn(['sent_wordpress', 'sent_wordpress_at']);
        });
    }
};
