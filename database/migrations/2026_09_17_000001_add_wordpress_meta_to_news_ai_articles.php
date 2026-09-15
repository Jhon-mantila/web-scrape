<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_ai_articles', function (Blueprint $table) {
            $table->unsignedBigInteger('wordpress_post_id')->nullable()->after('sent_wordpress_at');
            $table->string('wordpress_status', 20)->nullable()->after('wordpress_post_id');
            $table->timestamp('wordpress_scheduled_at')->nullable()->after('wordpress_status');
            $table->string('wordpress_url')->nullable()->after('wordpress_scheduled_at');
            $table->string('wordpress_author')->nullable()->after('wordpress_url');

            $table->index('wordpress_status');
            $table->index('wordpress_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('news_ai_articles', function (Blueprint $table) {
            $table->dropIndex(['wordpress_status']);
            $table->dropIndex(['wordpress_scheduled_at']);
            $table->dropColumn([
                'wordpress_post_id',
                'wordpress_status',
                'wordpress_scheduled_at',
                'wordpress_url',
                'wordpress_author',
            ]);
        });
    }
};
