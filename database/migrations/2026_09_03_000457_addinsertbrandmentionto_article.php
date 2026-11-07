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
        Schema::table('article_sections', function (Blueprint $table) {
            $table->boolean('insert_brand_mention')
                ->default(false)
                ->after('insert_internal_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_sections', function (Blueprint $table) {
            $table->dropColumn('insert_brand_mention');
        });
    }
};
