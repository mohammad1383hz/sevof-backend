<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('is_wordpress')->default(false)->after('url');
            $table->boolean('is_woocommerce')->default(false)->after('is_wordpress');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['is_wordpress', 'is_woocommerce']);
        });
    }
};
