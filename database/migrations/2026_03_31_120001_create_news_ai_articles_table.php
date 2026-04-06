<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_ai_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->string('source_title');
            $table->string('generated_title')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('body_html');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->unique('news_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_ai_articles');
    }
};
