<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_idea_related_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_idea_id')->constrained()->cascadeOnDelete();

            $table->foreignId('wordpress_article_id')
                ->constrained('site_wordpress_articles')
                ->cascadeOnDelete();

            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('url');
            $table->string('title');
            $table->unsignedInteger('match_score')->default(0);
            $table->timestamps();

            // ⬅️ تغییر: اسم پیش‌فرضی که لاراول می‌سازه
            // (article_idea_related_links_article_idea_id_wordpress_article_id_unique)
            // از حد مجاز ۶۴ کاراکتری MySQL برای identifier رد می‌شه؛ پس اسم کوتاه صریح می‌دیم.
            $table->unique(
                ['article_idea_id', 'wordpress_article_id'],
                'idea_related_links_unique'
            );

            $table->index('article_idea_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_idea_related_links');
    }
};
