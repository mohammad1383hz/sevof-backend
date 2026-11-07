<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_settings', 'default_publish_status')) {
                // draft | publish
                // وقتی مقاله از Laravel به سمت پلاگین وردپرس ارسال می‌شود، این وضعیت
                // به‌عنوان post_status استفاده می‌شود. پیش‌فرض draft تا کاربر در وردپرس
                // محتوای تولیدشده را بازبینی و سپس منتشر کند.
                $table->string('default_publish_status')->default('draft')->after('daily_article_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            if (Schema::hasColumn('agent_settings', 'default_publish_status')) {
                $table->dropColumn('default_publish_status');
            }
        });
    }
};
