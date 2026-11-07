<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * اجرای migration.
     */
    public function up(): void
    {
        Schema::table('article_idea_related_links', function (Blueprint $table) {
            $table->integer('match_score')->nullable()->change();
        });
    }

    /**
     * برگردوندن migration.
     */
    public function down(): void
    {
        Schema::table('article_idea_related_links', function (Blueprint $table) {
            $table->integer('match_score')->nullable(false)->change();
        });
    }
};
