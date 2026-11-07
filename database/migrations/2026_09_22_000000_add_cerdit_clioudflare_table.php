<?php

use App\Models\CreditCloudflare;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // از getTable() مدل استفاده می‌کنیم تا مطمئن باشیم دقیقاً همون جدولیه
        // که CreditCloudflare بهش map می‌شه (چه credit_cloudflares باشه چه
        // credits_cloudflare یا هر اسم دیگه‌ای که تنظیم شده).
        Schema::table((new CreditCloudflare)->getTable(), function (Blueprint $table) {
            $table->unsignedTinyInteger('active_requests')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table((new CreditCloudflare)->getTable(), function (Blueprint $table) {
            $table->dropColumn('active_requests');
        });
    }
};
