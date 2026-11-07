<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credits_cloudflare', function (Blueprint $table) {
            $table->string('type')->default('cloudflare')->after('id');

            // اگه account_id فعلاً NOT NULL هست، برای ردیف‌های shortapi
            // (که account_id معنی نداره) باید nullable بشه.
            // اگه از قبل nullable بوده این خط رو حذف کن.
            $table->string('account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('credits_cloudflare', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
