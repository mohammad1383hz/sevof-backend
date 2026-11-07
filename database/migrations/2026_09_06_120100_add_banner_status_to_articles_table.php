<?php
// database/migrations/2026_09_06_120100_add_banner_status_to_articles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // pending | generating | generated | failed
            // (اسم ستون رو بعد از image_status گذاشتم چون همون الگو رو دنبال می‌کنه؛
            //  اگه ستون image_status اسم دیگه‌ای داره، همین یک خط رو اصلاح کن)
            $table->string('banner_status')->default('pending')->after('image_status');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('banner_status');
        });
    }
};
