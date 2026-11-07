<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «آپدیت مقاله‌های گذشته»: پست‌هایی از سایت که با یوتیکس ساخته نشده‌اند (از افزونه ۱.۹ به بعد).
 * - site_wordpress_articles: اطلاعاتی که افزونه می‌دهد (قابل ویرایش بودن، تعداد کلمه، آخرین تغییر)
 * - site_connections: وضعیت آخرین خواندن پست‌ها از سایت
 * - article_refreshes: یک به‌روزرسانی حالا یا مال مقاله‌ی یوتیکس است یا مال پست قدیمی سایت
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_wordpress_articles', function (Blueprint $table) {
            // null = هنوز افزونه تأیید نکرده؛ false = پست خود سایت (نه یوتیکس) که افزونه فهرستش کرده
            $table->boolean('managed')->nullable()->after('tags');
            $table->boolean('editable')->nullable()->after('managed');
            $table->string('not_editable_reason', 30)->nullable()->after('editable');
            $table->unsignedInteger('word_count')->nullable()->after('not_editable_reason');
            $table->timestamp('modified_at')->nullable()->after('published_at');
            $table->timestamp('plugin_synced_at')->nullable()->after('synced_at');
        });

        Schema::table('site_connections', function (Blueprint $table) {
            $table->string('past_posts_sync_status', 20)->nullable()->after('last_error');
            $table->timestamp('past_posts_synced_at')->nullable()->after('past_posts_sync_status');
            $table->text('past_posts_sync_error')->nullable()->after('past_posts_synced_at');
        });

        Schema::table('article_refreshes', function (Blueprint $table) {
            $table->string('source', 20)->default('article')->after('site_id');
            $table->unsignedBigInteger('article_id')->nullable()->change();
            $table->foreignId('wordpress_article_id')->nullable()->after('article_id')
                ->constrained('site_wordpress_articles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('article_refreshes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wordpress_article_id');
            $table->dropColumn('source');
        });

        Schema::table('site_connections', function (Blueprint $table) {
            $table->dropColumn(['past_posts_sync_status', 'past_posts_synced_at', 'past_posts_sync_error']);
        });

        Schema::table('site_wordpress_articles', function (Blueprint $table) {
            $table->dropColumn(['managed', 'editable', 'not_editable_reason', 'word_count', 'modified_at', 'plugin_synced_at']);
        });
    }
};
