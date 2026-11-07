<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            // ساعت شروع فعالیت روزانه Agent — عدد ۰ تا ۲۳ (بر اساس timezone سایت/سرور)
            $table->unsignedTinyInteger('daily_start_hour')->default(0)->after('daily_article_limit');
        });
    }

    public function down(): void
    {
        Schema::table('agent_settings', function (Blueprint $table) {
            $table->dropColumn('daily_start_hour');
        });
    }
};
