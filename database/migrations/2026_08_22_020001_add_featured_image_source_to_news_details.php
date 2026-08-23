<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->string('featured_image_source', 20)->nullable()->after('featured_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('news_details', function (Blueprint $table) {
            $table->dropColumn('featured_image_source');
        });
    }
};
