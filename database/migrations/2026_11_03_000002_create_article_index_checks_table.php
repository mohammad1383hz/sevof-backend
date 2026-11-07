<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وضعیت ایندکس شدن مقاله‌های تولیدشده (مقاله و مقاله‌ی محصول) در گوگل، از URL Inspection API سرچ کنسول.
 * فقط برای ادمین؛ برای هر مقاله یک ردیف با آخرین نتیجه‌ی بررسی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_index_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // article | product_article ؛ شناسه‌ی مقاله‌ی محصول ULID است، پس رشته
            $table->string('subject_type', 20);
            $table->string('subject_id', 40);
            $table->string('title', 255);
            $table->text('url');
            $table->timestamp('published_at')->nullable();

            $table->boolean('is_indexed')->nullable();
            $table->string('verdict', 30)->nullable();
            $table->string('coverage_state', 191)->nullable();
            $table->string('indexing_state', 60)->nullable();
            $table->string('robots_txt_state', 30)->nullable();
            $table->string('page_fetch_state', 40)->nullable();
            $table->timestamp('last_crawl_at')->nullable();
            $table->text('google_canonical')->nullable();
            $table->text('inspection_link')->nullable();
            $table->timestamp('first_indexed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('checks_count')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['site_id', 'is_indexed']);
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_index_checks');
    }
};
