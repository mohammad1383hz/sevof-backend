<?php
// database/migrations/xxxx_xx_xx_add_article_prompt_and_sections_to_articles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->text('article_user_prompt')->nullable()->after('image_user_prompt');
            $table->unsignedTinyInteger('min_sections')->nullable()->after('article_user_prompt');
            $table->unsignedTinyInteger('max_sections')->nullable()->after('min_sections');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['article_user_prompt', 'min_sections', 'max_sections']);
        });
    }
};
