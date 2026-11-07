<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نتیجه‌ی بررسی سئوی متن هر پست قدیمی (لینک داخلی، سال‌های قدیمی، طول، تیترها) برای تشخیص «آپدیت لازم دارد».
 * متن پست در فهرست افزونه نیست؛ بعد از هر خواندن فهرست، در پس‌زمینه یکی‌یکی خوانده و بررسی می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_wordpress_articles', function (Blueprint $table) {
            $table->json('audit')->nullable();
            $table->timestamp('audited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_wordpress_articles', function (Blueprint $table) {
            $table->dropColumn(['audit', 'audited_at']);
        });
    }
};
