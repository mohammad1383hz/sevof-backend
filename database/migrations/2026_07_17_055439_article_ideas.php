<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')
                ->constrained('sites')
                ->cascadeOnDelete();

            $table->string('title');
            $table->string('meta_description')->nullable();
            $table->string('hook')->nullable(); // زاویه‌ی جذاب برای مخاطب

            // entity مرتبط با این ایده
            $table->string('entity_name');
            $table->enum('entity_scope', ['primary', 'core'])->default('core');
            $table->string('entity_type')->nullable(); // service, product, category, ...

            // لینک داخلی پیشنهادی (صفحه‌ی همون entity برای internal link)
            $table->string('target_url')->nullable();
            $table->string('target_url_title')->nullable();

            $table->unsignedTinyInteger('priority')->default(50); // 0-100
            $table->json('outline')->nullable(); // تیترهای پیشنهادی مقاله

            $table->enum('status', ['suggested', 'approved', 'rejected', 'used'])
                ->default('suggested');

            $table->json('ai_raw_response')->nullable();
 $table->unsignedTinyInteger('seo_score')->nullable();
$table->json('score_breakdown')->nullable();
$table->json('serp_data')->nullable();
$table->timestamp('scored_at')->nullable();
$table->string('scoring_error')->nullable();

            $table->index('seo_score');
            $table->timestamps();

            $table->index(['website_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_ideas');
    }
};
