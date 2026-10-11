<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_videos', function (Blueprint $table) {
            $table->string('caption_ai_provider', 32)->default('ollama_local')->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('social_videos', function (Blueprint $table) {
            $table->dropColumn('caption_ai_provider');
        });
    }
};
