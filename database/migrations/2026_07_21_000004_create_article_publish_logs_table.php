<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // publish | update | delete | sync_status
            $table->string('action');
            // success | failed
            $table->string('status');

            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_publish_logs');
    }
};
