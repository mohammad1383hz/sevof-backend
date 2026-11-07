<?php
// database/migrations/2026_09_12_000000_add_image_gallery_to_articles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (! Schema::hasColumn('articles', 'image_gallery')) {
                $table->json('image_gallery')->nullable()->after('image_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (Schema::hasColumn('articles', 'image_gallery')) {
                $table->dropColumn('image_gallery');
            }
        });
    }
};
