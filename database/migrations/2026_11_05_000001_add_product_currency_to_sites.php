<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * واحد قیمت محصولات سایت که ادمین تعیین می‌کند: IRR (ریال) یا IRT (تومان).
 * خالی یعنی مثل همیشه (تومان)؛ فقط برای فروشگاهی که قیمت‌هایش ریالی است پر می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('product_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('product_currency');
        });
    }
};
