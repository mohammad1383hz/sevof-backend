<?php
// database/migrations/2026_09_22_000001_add_ai_idempotency_markers_to_articles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // نتیجه‌ی خام outline که AI پولش را گرفته، مستقل از موفقیت ذخیره‌ی سکشن‌ها.
            $table->json('raw_outline_result')->nullable()->after('brief');

            // marker های idempotency: فقط بعد از موفقیت کامل و قطعی هر مرحله ست می‌شوند.
            $table->timestamp('seo_reviewed_at')->nullable()->after('seo_review');
            $table->timestamp('meta_generated_at')->nullable()->after('faq_schema');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['raw_outline_result', 'seo_reviewed_at', 'meta_generated_at']);
        });
    }
};
