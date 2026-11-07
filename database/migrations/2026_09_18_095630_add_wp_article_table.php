<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_wordpress_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('wp_post_id');
            $table->string('title');
            $table->string('url');
            $table->text('excerpt')->nullable();
            // هر آیتم: ['id' => int, 'name' => string, 'slug' => string]
            $table->json('categories')->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'wp_post_id']);
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_wordpress_articles');
    }
};
