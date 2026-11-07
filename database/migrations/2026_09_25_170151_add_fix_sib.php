<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ستون starts_at باید nullable باشه چون
     * SubscriptionService::queueSubscription() عمداً starts_at رو
     * هنگام صف‌کردن یک پلن (status=queued) مقدار null می‌ده —
     * تا وقتی که activateNextQueuedSubscription() پلن رو فعال کنه
     * و این تاریخ رو پر کنه. در migration اصلی (create table)
     * ends_at از قبل nullable() بوده، فقط starts_at رو NOT NULL
     * گذاشته بودن و همین باعث خطای Integrity constraint violation
     * (1048) روی هر queueSubscription می‌شه، درحالی که پرداخت کاربر
     * از زرین‌پال قبلش با موفقیت verify شده. فقط همین یک ستون تغییر
     * می‌کنه؛ ends_at و بقیه‌ی ستون‌ها دست نمی‌خورن.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable(false)->change();
        });
    }
};
