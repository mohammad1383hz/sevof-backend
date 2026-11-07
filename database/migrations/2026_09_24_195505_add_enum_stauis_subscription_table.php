<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * این migration فقط لازمه اگه ستون status توی جدول subscriptions
 * واقعاً از نوع ENUM دیتابیسی باشه. اگه از اول string/varchar بوده
 * (که در پروژه‌های Laravel خیلی رایج‌تره چون Eloquent محدودیتی روش
 * نمی‌ذاره)، این migration لازم نیست و می‌تونی حذفش کنی — مقدار
 * 'queued' بدون هیچ تغییری در دیتابیس قابل ذخیره‌ست.
 *
 * پیش از اجرا با این دستور مطمئن شو ستون چطور تعریف شده:
 *   SHOW COLUMNS FROM subscriptions WHERE Field = 'status';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isMysqlEnum()) {
            return;
        }

        DB::statement(
            "ALTER TABLE subscriptions MODIFY COLUMN status ENUM('active', 'expired', 'cancelled', 'queued') NOT NULL DEFAULT 'active'"
        );
    }

    public function down(): void
    {
        if (! $this->isMysqlEnum()) {
            return;
        }

        // قبل از برگردوندن enum، هر ردیف queued رو به یه مقدار مجاز تبدیل کن
        // وگرنه ALTER زیر با خطا مواجه می‌شه یا ردیف‌ها null می‌شن.
        DB::table('subscriptions')
            ->where('status', 'queued')
            ->update(['status' => 'cancelled']);

        DB::statement(
            "ALTER TABLE subscriptions MODIFY COLUMN status ENUM('active', 'expired', 'cancelled') NOT NULL DEFAULT 'active'"
        );
    }

    protected function isMysqlEnum(): bool
    {
        if (! Schema::hasColumn('subscriptions', 'status')) {
            return false;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        $column = DB::selectOne(
            "SELECT COLUMN_TYPE as type FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'status'"
        );

        return $column !== null && str_starts_with($column->type, 'enum');
    }
};
