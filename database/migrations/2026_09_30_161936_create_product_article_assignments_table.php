<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_article_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_article_id')->constrained('product_articles')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('type', 30);
            $table->string('heading');
            $table->json('subheadings')->nullable();
            $table->json('key_points')->nullable();
            $table->unsignedInteger('target_words')->default(0);
            $table->json('product_ids')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->unique(['product_article_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_article_assignments');
    }
};
