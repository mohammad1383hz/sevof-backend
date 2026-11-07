<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * این migration دو ستون را (در صورت نبودن) به جدول agent_settings اضافه می‌کند:
     *
     * - daily_start_hour       ساعت شروع فعالیت روزانه‌ی Auto Pilot (۰ تا ۲۳)
     * - publish_interval_days  فاصله‌ی هر چرخه‌ی تولید/انتشار بر حسب روز
     *                          (۱ = هر روز، ۲ = یک روز در میان، ۷ = هفتگی، یا هر عدد دلخواه)
     *
     * چون معلوم نبود daily_start_hour قبلاً در migration دیگری اضافه شده یا نه،
     * هر دو با hasColumn محافظت شده‌اند تا اجرای مجدد یا تداخل با migration های قبلی مشکلی ایجاد نکند.
     */
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_settings', 'daily_start_hour')) {
                $table->unsignedTinyInteger('daily_start_hour')
                    ->default(9)
                    ->after('default_publish_status')
                    ->comment('ساعت شروع فعالیت روزانه‌ی ایجنت (۰ تا ۲۳)');
            }

            if (! Schema::hasColumn('agent_settings', 'publish_interval_days')) {
                $table->unsignedTinyInteger('publish_interval_days')
                    ->default(1)
                    ->after('daily_article_limit')
                    ->comment('فاصله‌ی هر چرخه‌ی تولید/انتشار به روز؛ ۱=هرروز، ۲=یک‌روزدرمیان، ۷=هفتگی و ...');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            if (Schema::hasColumn('agent_settings', 'publish_interval_days')) {
                $table->dropColumn('publish_interval_days');
            }

            if (Schema::hasColumn('agent_settings', 'daily_start_hour')) {
                $table->dropColumn('daily_start_hour');
            }
        });
    }
};
