<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_articles', function (Blueprint $table) {
            $table->text('article_user_prompt')->nullable()->after('selected_model');
            $table->unsignedTinyInteger('min_sections')->nullable()->after('article_user_prompt');
            $table->unsignedTinyInteger('max_sections')->nullable()->after('min_sections');
        });
    }

    public function down(): void
    {
        Schema::table('product_articles', function (Blueprint $table) {
            $table->dropColumn(['article_user_prompt', 'min_sections', 'max_sections']);
        });
    }
};
