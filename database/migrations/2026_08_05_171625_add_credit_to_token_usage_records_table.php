<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('token_usage_records', function (Blueprint $table) {
            // اضافه کردن ستون credit بعد از ستون cost_usd
            $table->decimal('credit', 12, 4)->default(0)->after('cost_usd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('token_usage_records', function (Blueprint $table) {
            $table->dropColumn('credit');
        });
    }
};
