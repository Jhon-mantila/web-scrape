<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->text('research_context')->nullable()->after('featured_image_path');
            $table->json('research_raw')->nullable()->after('research_context');
            $table->timestamp('researched_at')->nullable()->after('research_raw');
        });
    }

    public function down(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->dropColumn(['research_context', 'research_raw', 'researched_at']);
        });
    }
};
