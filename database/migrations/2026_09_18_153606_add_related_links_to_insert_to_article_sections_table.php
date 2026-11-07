<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_sections', function (Blueprint $table) {
            $table->json('related_links_to_insert')->nullable()->after('insert_internal_link');
        });
    }

    public function down(): void
    {
        Schema::table('article_sections', function (Blueprint $table) {
            $table->dropColumn('related_links_to_insert');
        });
    }
};
