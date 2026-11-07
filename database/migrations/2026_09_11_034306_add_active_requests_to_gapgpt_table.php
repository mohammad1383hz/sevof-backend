<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gapgpt', function (Blueprint $table) {
            // تعداد درخواست‌هایی که این کلید همین الان داره همزمان پردازش می‌کنه
            $table->unsignedInteger('active_requests')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('gapgpt', function (Blueprint $table) {
            $table->dropColumn('active_requests');
        });
    }
};
