<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * شناسه‌ی عمومی (ULID) برای هر چیزی که در آدرس فرانت یا مسیر API می‌آید.
 * آی‌دی عددی و همه‌ی کلیدهای خارجی دست‌نخورده می‌مانند؛ فقط ستون token اضافه و برای ردیف‌های قبلی پر می‌شود.
 */
return new class extends Migration
{
    private const TABLES = [
        'sites',
        'articles',
        'article_ideas',
        'article_sections',
        'article_refreshes',
        'site_wordpress_articles',
        'product_seo_ideas',
        'page_product_seo_optimizations',
        'content_research_sessions',
        'content_chat_messages',
        'tickets',
        'plans',
        'api_agents',
        'page_visits',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'token')) {
                Schema::table($table, function (Blueprint $t) {
                    // nullable تا هیچ insert قدیمی‌ای نشکند؛ مدل‌ها موقع ساخت خودشان پرش می‌کنند
                    $t->string('token', 26)->nullable()->unique()->after('id');
                });
            }

            $wrapped = DB::getQueryGrammar()->wrapTable($table);

            // هر ۱۰۰۰ ردیف با یک UPDATE؛ برای جدول‌های بزرگ مثل page_visits هم سریع است
            DB::table($table)->whereNull('token')->select('id')->chunkById(1000, function ($rows) use ($wrapped) {
                $cases = $rows->map(fn ($row) => 'WHEN '.(int) $row->id." THEN '".strtolower((string) Str::ulid())."'")->implode(' ');
                $ids = $rows->map(fn ($row) => (int) $row->id)->implode(',');

                DB::update("UPDATE {$wrapped} SET token = CASE id {$cases} END WHERE id IN ({$ids})");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'token')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropUnique($table.'_token_unique');
                    $t->dropColumn('token');
                });
            }
        }
    }
};
