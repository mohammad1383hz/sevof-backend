<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            // کد تخفیف: ۵ کاراکتری، ترکیب حروف انگلیسی بزرگ و عدد. مثلاً: A3F9K
            $table->string('code', 5)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};

