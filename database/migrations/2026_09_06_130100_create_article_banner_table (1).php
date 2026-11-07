<?php
// database/migrations/2026_09_06_130100_create_article_banner_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_banner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banner_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // یک بنر مشخص روی یک مقاله‌ی مشخص فقط یک‌بار می‌تونه attach بشه
            $table->unique(['article_id', 'banner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_banner');
    }
};
