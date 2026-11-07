<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_seo_ideas', function (Blueprint $t) {
            $t->unsignedBigInteger('target_category_id')->nullable()->after('content_style');
            $t->text('target_url')->nullable()->after('target_category_id');
            $t->string('target_url_title')->nullable()->after('target_url');
        });

        // سرویس این دو ستون را می‌خواند؛ اگر قبلاً نبودند ساخته می‌شوند
        if (! Schema::hasColumn('site_product_categories', 'url')) {
            Schema::table('site_product_categories', fn (Blueprint $t) => $t->text('url')->nullable());
        }

        if (! Schema::hasColumn('site_products', 'category_ids')) {
            Schema::table('site_products', fn (Blueprint $t) => $t->json('category_ids')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('product_seo_ideas', function (Blueprint $t) {
            $t->dropColumn(['target_category_id', 'target_url', 'target_url_title']);
        });
    }
};
