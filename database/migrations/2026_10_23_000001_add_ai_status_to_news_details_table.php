<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->string('ai_status', 32)->nullable()->after('status');
            $table->timestamp('ai_status_updated_at')->nullable()->after('ai_status');
        });
    }

    public function down(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->dropColumn(['ai_status', 'ai_status_updated_at']);
        });
    }
};
