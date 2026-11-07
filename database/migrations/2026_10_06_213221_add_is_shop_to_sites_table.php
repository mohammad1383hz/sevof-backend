<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('is_shop')->default(false)->after('is_woocommerce')->index();
            $table->timestamp('products_synced_at')->nullable()->after('is_shop');
        });

        // سایت‌های ووکامرسیِ قبلی فروشگاه حساب شوند
        DB::table('sites')->where('is_woocommerce', true)->update(['is_shop' => true]);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['is_shop', 'products_synced_at']);
        });
    }
};
