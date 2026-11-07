<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // کد ISO 639-1 زبان اصلی سایت (fa, en, ar, ...). null = قابل تشخیص نبود.
            $table->string('language', 10)->nullable();

            // درصد اطمینان تشخیص (۰ تا ۱۰۰)
            $table->unsignedTinyInteger('language_confidence')->nullable();

            // منبع تصمیم نهایی: content / content+declared / content+declared+ai / declared+ai / ...
            $table->string('language_source', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['language', 'language_confidence', 'language_source']);
        });
    }
};
