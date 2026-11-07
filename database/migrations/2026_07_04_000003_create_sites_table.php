<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('url');
            $table->string('status')->default('pending');
            $table->string('niche')->nullable();
            $table->text('summary')->nullable();
            $table->json('crawled_pages')->nullable();
            $table->json('ai_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'domain']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
