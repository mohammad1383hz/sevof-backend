<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_usage_records', function (Blueprint $table) {
            // دقت بالا (۱۰ رقم اعشار) چون هزینه‌ی هر پیام معمولاً کسری از سِنت است
            // و گرد کردن زودهنگام باعث خطای انباشته در جمع‌های کلی می‌شود.
            $table->decimal('cost_usd', 12, 10)->nullable()->after('total_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('token_usage_records', function (Blueprint $table) {
            $table->dropColumn('cost_usd');
        });
    }
};
