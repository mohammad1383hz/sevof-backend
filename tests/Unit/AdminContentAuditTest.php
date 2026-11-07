<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\AdminContentAuditController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** گزارش ادمین: همه‌ی اعداد (حتی صفر) و جزئیات هر مقاله، تاریخچه‌ی آپدیت و لینک‌های تازه. */
class AdminContentAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('user_id');
            $t->string('domain');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('site_wordpress_articles', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('wp_post_id');
            $t->string('title');
            $t->text('url');
            $t->boolean('managed')->nullable();
            $t->boolean('editable')->nullable();
            $t->string('not_editable_reason')->nullable();
            $t->integer('word_count')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('modified_at')->nullable();
            $t->json('audit')->nullable();
            $t->timestamp('audited_at')->nullable();
            $t->timestamps();
        });
        Schema::create('article_refreshes', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->string('source')->default('article');
            $t->unsignedBigInteger('article_id')->nullable();
            $t->unsignedBigInteger('wordpress_article_id')->nullable();
            $t->string('status');
            $t->string('trigger')->default('manual');
            $t->string('reason');
            $t->json('after')->nullable();
            $t->text('summary')->nullable();
            $t->decimal('credit_used', 12, 4)->default(0);
            $t->text('error')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });
        Schema::create('search_console_page_metrics', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->string('page_hash');
            foreach (['clicks', 'previous_clicks', 'impressions', 'previous_impressions'] as $c) {
                $t->integer($c)->nullable();
            }
            $t->float('position')->nullable();
            $t->float('previous_position')->nullable();
        });

        DB::table('users')->insert(['id' => 1, 'name' => 'مریم']);
        DB::table('sites')->insert(['id' => 3, 'user_id' => 1, 'domain' => 'shop.ir']);
        DB::table('site_wordpress_articles')->insert([
            ['id' => 1, 'site_id' => 3, 'wp_post_id' => 11, 'title' => 'راهنمای گلدان ۱۴۰۲', 'url' => 'https://shop.ir/pot', 'managed' => false, 'editable' => true, 'published_at' => '2023-01-01', 'modified_at' => '2026-10-01',
                'audit' => json_encode(['words' => 1600, 'internal_links' => 0, 'external_links' => 2, 'headings' => 4, 'outdated_years' => [1402], 'internal_urls' => []]), 'audited_at' => '2026-10-02'],
            ['id' => 2, 'site_id' => 3, 'wp_post_id' => 12, 'title' => 'آبیاری گیاهان', 'url' => 'https://shop.ir/water', 'managed' => false, 'editable' => true, 'published_at' => '2026-01-01', 'modified_at' => '2026-10-01',
                'audit' => null, 'audited_at' => null],
        ]);
        DB::table('article_refreshes')->insert([
            'site_id' => 3, 'source' => 'wp_post', 'wordpress_article_id' => 1, 'status' => 'applied', 'reason' => 'manual', 'credit_used' => 3.5, 'created_at' => '2026-10-03',
            'after' => json_encode(['sections' => [['id' => 0]], 'links' => [['n' => 1, 'anchor' => 'انتخاب خاک گلدان', 'url' => 'https://shop.ir/soil', 'title' => 'خاک گلدان', 'kind' => 'youtix', 'section' => 'مقدمه']]]),
        ]);
    }

    public function test_report_shows_every_number_issue_and_new_link(): void
    {
        $this->withoutVite();

        $html = app(AdminContentAuditController::class)
            ->index(Request::create('/admin/content-audit', 'GET', ['site_id' => 3]), app(\App\Services\SearchConsoleInsightsService::class))
            ->render();

        $this->assertStringContainsString('گزارش سئوی مقاله‌های گذشته', $html);
        $this->assertStringContainsString('shop.ir', $html);
        $this->assertStringContainsString('No internal links', $html);
        $this->assertStringContainsString('Outdated year in title', $html);
        // ادمین صفر را هم می‌بیند
        $this->assertMatchesRegularExpression('/لینک داخلی: <strong[^>]*>0<\/strong> از 4 پیشنهادی/u', $html);
        $this->assertStringContainsString('متن هنوز بررسی نشده', $html);
        $this->assertStringContainsString('«انتخاب خاک گلدان»', $html);
        $this->assertStringContainsString('https://shop.ir/soil', $html);
    }

    public function test_filter_keeps_only_matching_articles(): void
    {
        $this->withoutVite();

        $html = app(AdminContentAuditController::class)
            ->index(Request::create('/admin/content-audit', 'GET', ['site_id' => 3, 'filter' => 'not_audited']), app(\App\Services\SearchConsoleInsightsService::class))
            ->render();

        $this->assertStringContainsString('آبیاری گیاهان', $html);
        $this->assertStringNotContainsString('راهنمای گلدان ۱۴۰۲</p>', $html);
    }
}
