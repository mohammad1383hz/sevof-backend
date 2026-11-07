<?php
// database/migrations/xxxx_xx_xx_add_source_to_articles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // دیفالت 'manual' یعنی مقاله‌ی ساخته‌شده توسط خود کاربر است
            $table->string('source')->default('manual')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
