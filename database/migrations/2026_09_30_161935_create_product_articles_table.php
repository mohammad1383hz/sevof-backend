<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_seo_idea_id')->nullable()->constrained('product_seo_ideas')->nullOnDelete();
            $table->string('title');
            $table->string('content_style', 30)->default('buying_guide');
            $table->json('categories')->nullable();
            $table->json('product_ids');
            $table->string('status', 40)->default('pending')->index();
            $table->json('brief')->nullable();
            $table->json('raw_assignments_result')->nullable();
            $table->string('selected_model')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_articles');
    }
};
