<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // url: آدرس‌های فارسی بعد از percent-encode بیش از ۱۴۰۰ کاراکتر می‌شوند → text
        Schema::table('site_products', function (Blueprint $table) {
            $table->text('url')->nullable()->change();
            $table->text('name')->change();
            $table->string('external_id', 500)->nullable()->change();
            $table->string('sku', 500)->nullable()->change();
            // slug در ایندکس unique است، پس text نمی‌شود؛ فقط تا ۵۰۰ بزرگ می‌شود
            $table->string('slug', 500)->change();
        });

        // دسته‌ها هم همین مشکل را دارند (name / slug / url)
        Schema::table('site_product_categories', function (Blueprint $table) {
            $table->text('url')->nullable()->change();
            $table->text('name')->change();
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('site_products', function (Blueprint $table) {
            $table->string('url')->nullable()->change();
            $table->string('name')->change();
            $table->string('external_id')->nullable()->change();
            $table->string('sku')->nullable()->change();
            $table->string('slug')->change();
        });

        Schema::table('site_product_categories', function (Blueprint $table) {
            $table->string('url')->nullable()->change();
            $table->string('name')->change();
        });
    }
};
