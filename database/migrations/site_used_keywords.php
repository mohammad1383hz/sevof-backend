<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_used_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('title')->nullable(); // موضوع/عنوان مقاله‌ای که با این کلمه ساخته شده
            $table->timestamps();

            // جلوگیری از ذخیره تکراری در سطح دیتابیس
            $table->unique(['site_id', 'keyword']);
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_used_keywords');
    }
};