<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_publications', function (Blueprint $table) {
            $table->timestamp('queued_publish_at')->nullable()->after('scheduled_at');
            $table->index(['queued_publish_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('social_publications', function (Blueprint $table) {
            $table->dropIndex(['queued_publish_at', 'status']);
            $table->dropColumn('queued_publish_at');
        });
    }
};
