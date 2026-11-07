<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_idea_related_links', function (Blueprint $table) {
            $table->unsignedBigInteger('wordpress_article_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('article_idea_related_links', function (Blueprint $table) {
            $table->unsignedBigInteger('wordpress_article_id')->nullable(false)->change();
        });
    }
};
