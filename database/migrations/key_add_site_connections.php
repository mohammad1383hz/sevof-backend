<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * فیلد صرفاً یادداشتی/اطلاعاتی است: مشخص می‌کند این سایت به‌صورت دستی
     * آدرس Relay را در تنظیمات پلاگین وردپرس خودش وارد کرده است یا نه.
     * هیچ منطقی در جریان اتصال (connect/reconnect/test) به این مقدار
     * وابسته نیست؛ فقط برای نمایش/فیلتر در پنل ادمین استفاده می‌شود.
     */
    public function up(): void
    {
        Schema::table('site_connections', function (Blueprint $table) {
            $table->boolean('uses_relay')->default(false)->after('wp_url');
            $table->string('relay_note')->nullable()->after('uses_relay');
        });
    }

    public function down(): void
    {
        Schema::table('site_connections', function (Blueprint $table) {
            $table->dropColumn(['uses_relay', 'relay_note']);
        });
    }
};
