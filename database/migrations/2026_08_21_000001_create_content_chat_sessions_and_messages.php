<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200)->default('گفتگوی جدید');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'user_id', 'last_message_at']);
        });

        Schema::create('content_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_chat_session_id')
                ->constrained('content_chat_sessions')
                ->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('content_research_session_id')
                ->nullable()
                ->constrained('content_research_sessions')
                ->nullOnDelete();
            $table->string('role', 20);
            $table->string('kind', 50);
            $table->text('content');
            $table->string('label', 255)->nullable();
            $table->json('entity')->nullable();
            $table->json('options')->nullable();
            $table->boolean('answered')->default(false);
            $table->unsignedInteger('batch')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['content_chat_session_id', 'created_at']);
            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_chat_messages');
        Schema::dropIfExists('content_chat_sessions');
    }
};
