<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_videos', function (Blueprint $table) {
            $table->string('caption_ollama_target', 16)->default('local')->after('caption_ai_provider');
            $table->string('caption_ai_model', 120)->nullable()->after('caption_ollama_target');
        });
    }

    public function down(): void
    {
        Schema::table('social_videos', function (Blueprint $table) {
            $table->dropColumn(['caption_ollama_target', 'caption_ai_model']);
        });
    }
};
