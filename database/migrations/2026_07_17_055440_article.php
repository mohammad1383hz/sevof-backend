<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_idea_id')->constrained('article_ideas')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('sites')->cascadeOnDelete();

            $table->string('title');
            $table->string('meta_description')->nullable();

            // pending, creating_brief, generating_outline, generating_sections, reviewing, completed, failed
            $table->string('status')->default('pending');

            $table->json('brief')->nullable();      // خروجی مرحله ۲
            $table->json('outline')->nullable();    // خروجی مرحله ۳
            $table->longText('content')->nullable(); // مقاله نهایی ترکیب‌شده (Markdown)

            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->json('seo_review')->nullable(); // breakdown + suggestions
            $table->unsignedInteger('word_count')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
        });

        Schema::create('article_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position'); // ترتیب بخش در مقاله
            $table->string('type')->default('section'); // introduction, section, faq, conclusion
            $table->string('heading');
            $table->json('subheadings')->nullable();  // H3 های زیر این بخش
            $table->json('key_points')->nullable();   // نکاتی که این بخش باید پوشش بده
            $table->unsignedSmallInteger('target_words')->default(300);

            $table->longText('content')->nullable();

            // pending, generated, failed
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_sections');
        Schema::dropIfExists('articles');
    }
};
