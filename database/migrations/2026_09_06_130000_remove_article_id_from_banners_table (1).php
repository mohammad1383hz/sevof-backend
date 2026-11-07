<?php
// database/migrations/2026_09_06_130000_remove_article_id_from_banners_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            // قبلاً: هر بنر دقیقاً برای یک مقاله بود (article_id unique).
            // الان: بنر متعلق به یک سایته (site_id می‌مونه) و می‌تونه به
            // چند مقاله وصل بشه؛ ارتباط با مقاله‌ها از این به بعد از طریق
            // جدول واسط article_banner انجام می‌شه.
            $table->dropForeign(['article_id']);
            $table->dropColumn('article_id');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->foreignId('article_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }
};
