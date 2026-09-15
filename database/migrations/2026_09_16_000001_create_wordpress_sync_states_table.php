<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('site', 64)->unique();
            $table->timestamp('newest_wp_published_at')->nullable();
            $table->unsignedInteger('backfill_next_page')->default(1);
            $table->boolean('backfill_complete')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_sync_states');
    }
};
