<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ۱) اول باید فارین‌کی فعلی روی user_id رو برداریم تا بتونیم nullable‌ش کنیم
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();

            $table->foreignId('admin_id')
                ->nullable()
                ->after('user_id')
                ->constrained('admins')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });

        // ۲) پاسخ‌های قبلی که با آی‌دی ادمین (به‌اشتباه) توی user_id ذخیره شده بودن رو
        // به admin_id منتقل می‌کنیم تا دیتای قبلی خراب نشه.
        // این کوئری فقط ردیف‌هایی رو دست می‌زنه که is_staff_reply = true و user_id
        // با یک آی‌دی موجود در جدول admins یکی باشه.
        DB::table('ticket_replies as tr')
            ->join('admins as a', 'a.id', '=', 'tr.user_id')
            ->where('tr.is_staff_reply', true)
            ->update([
                'tr.admin_id' => DB::raw('tr.user_id'),
                'tr.user_id' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
        });

        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
