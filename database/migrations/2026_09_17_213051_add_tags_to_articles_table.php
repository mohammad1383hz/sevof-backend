<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // آی‌دی دسته‌بندی وردپرسی که AI برای این مقاله تشخیص داده
            // (این آی‌دی مستقیماً معادل idهایی هست که داخل sites.categories ذخیره شده)
            $table->unsignedBigInteger('wp_category_id')->nullable()->after('status');

            // فقط برای نمایش راحت‌تر در پنل، بدون نیاز به join با site
            $table->string('wp_category_name')->nullable()->after('wp_category_id');

            // تگ‌های موجود سایت که AI برای این مقاله مرتبط تشخیص داده
            // فرمت: [1, 5, 12] (آرایه‌ای از id تگ‌های وردپرسی)
            $table->json('wp_tag_ids')->nullable()->after('wp_category_name');

            // تگ‌های جدیدی که در لیست تگ‌های سایت وجود نداشتن ولی AI پیشنهاد داده
            // موقع publish باید این‌ها اول توی وردپرس ساخته بشن و بعد id شون به wp_tag_ids اضافه بشه
            // فرمت: ["اسم تگ ۱", "اسم تگ ۲"]
            $table->json('wp_new_tags')->nullable()->after('wp_tag_ids');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['wp_category_id', 'wp_category_name', 'wp_tag_ids', 'wp_new_tags']);
        });
    }
};
