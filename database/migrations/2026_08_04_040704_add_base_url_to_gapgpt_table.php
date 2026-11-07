<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * هر API Key از این به بعد base_url مخصوص خودش رو داره
     * (چون قراره چند تا سرویس/اکانت مختلف با base URLهای متفاوت داشته باشیم)
     * و دیگه یک base_url ثابت از config برای همه‌ی کلیدها استفاده نمی‌شه.
     */
    public function up(): void
    {
        Schema::table('gapgpt', function (Blueprint $table) {
            $table->string('base_url')->nullable()->after('api_key');
        });

        // برای کلیدهای قبلی که از قبل تو جدول بودن، همون base_url فعلی config
        // رو به‌عنوان مقدار پیش‌فرض ست می‌کنیم تا بعد از migrate چیزی خراب نشه.
        $defaultBaseUrl = rtrim((string) config('seobot.openai_analyze.base_url'), '/');

        if ($defaultBaseUrl !== '') {
            DB::table('gapgpt')
                ->whereNull('base_url')
                ->update(['base_url' => $defaultBaseUrl]);
        }
    }

    public function down(): void
    {
        Schema::table('gapgpt', function (Blueprint $table) {
            $table->dropColumn('base_url');
        });
    }
};
