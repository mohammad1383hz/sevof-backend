<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * داده‌ی دقیق‌تر سرچ کنسول برای داشبورد نتیجه:
 * - آمار هر صفحه (بُعد page به‌تنهایی؛ جمع query+page کوئری‌های ناشناس گوگل را حساب نمی‌کند)
 * - جمع کل سایت در ۲۸ روز اخیر و ۲۸ روز قبل از آن
 * - page_hash روی آمار کوئری‌ها تا کوئری‌های هر مقاله سریع پیدا شود
 * - anchor_query روی لینک داخلی: کوئری واقعی‌ای که صفحه‌ی مقصد در گوگل برایش دیده می‌شود
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_console_page_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_search_console_property_id')
                ->constrained('site_search_console_properties', 'id', 'sc_page_metrics_property_fk')
                ->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('page', 2048);
            $table->char('page_hash', 40);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('previous_clicks')->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('previous_impressions')->nullable();
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('position', 10, 4)->nullable();
            $table->decimal('previous_position', 10, 4)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['site_id', 'page_hash'], 'sc_page_metrics_site_hash_idx');
        });

        Schema::table('search_console_query_metrics', function (Blueprint $table) {
            $table->char('page_hash', 40)->nullable()->after('page');
            $table->index(['site_id', 'page_hash'], 'sc_metrics_site_hash_idx');
        });

        Schema::table('site_search_console_properties', function (Blueprint $table) {
            $table->json('totals')->nullable()->after('last_sync_error');
        });

        Schema::table('article_idea_related_links', function (Blueprint $table) {
            $table->string('anchor_query', 255)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('article_idea_related_links', function (Blueprint $table) {
            $table->dropColumn('anchor_query');
        });

        Schema::table('site_search_console_properties', function (Blueprint $table) {
            $table->dropColumn('totals');
        });

        Schema::table('search_console_query_metrics', function (Blueprint $table) {
            $table->dropIndex('sc_metrics_site_hash_idx');
            $table->dropColumn('page_hash');
        });

        Schema::dropIfExists('search_console_page_metrics');
    }
};
