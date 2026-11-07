<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();

            $table->enum('role', ['user', 'assistant']);

            // section | faq | title | meta_title | meta_description | excerpt | general
            $table->string('target_type')->nullable();

            // ArticleSection.id برای section، ایندکس آرایه برای faq، برای بقیه null
            $table->unsignedInteger('target_id')->nullable();

            // برچسب نمایشی برای UI (heading بخش یا متن سوال FAQ)
            $table->string('target_label')->nullable();

            $table->text('content');

            // آیا این پیام (پاسخ AI) واقعاً باعث تغییر محتوا در دیتابیس شد؟
            $table->boolean('applied')->default(false);

            $table->timestamps();

            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_chat_messages');
    }
};
