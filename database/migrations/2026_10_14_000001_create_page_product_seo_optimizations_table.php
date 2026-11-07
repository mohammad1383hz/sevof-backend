<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_product_seo_optimizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_product_id')->constrained('site_products')->cascadeOnDelete();
            $table->unsignedInteger('batch')->default(1)->index();
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('needs_search')->nullable();
            $table->json('missing_fields')->nullable();   // فیلدهای ناقص (تشخیص با Laravel)
            $table->json('search_data')->nullable();      // مشخصات تأییدشده + منابع
            $table->json('result')->nullable();           // خروجی ساختاریافته‌ی AI بعد از کنترل کیفیت
            $table->json('approved_fields')->nullable();  // فیلدهایی که کاربر تأیید کرده
            $table->text('error')->nullable();

            $table->string('wp_sync_status', 20)->default('not_synced');
            $table->text('publish_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'site_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_product_seo_optimizations');
    }
};
