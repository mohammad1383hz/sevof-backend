<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_sections', function (Blueprint $table) {
            // مشخص می‌کند که آیا لینک داخلی برنامه‌ریزی‌شده باید دقیقاً در همین بخش قرار بگیرد یا نه.
            // این فیلد در مرحله‌ی تولید Outline توسط AI مقداردهی می‌شود (نه با حدس زدن بعدی بر اساس متن heading).
            $table->boolean('insert_internal_link')->default(false)->after('target_words');
        });
    }

    public function down(): void
    {
        Schema::table('article_sections', function (Blueprint $table) {
            $table->dropColumn('insert_internal_link');
        });
    }
};
