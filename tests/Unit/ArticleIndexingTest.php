<?php

namespace Tests\Unit;

use App\Exceptions\SearchConsoleQuotaException;
use App\Models\ArticleIndexCheck;
use App\Models\Site;
use App\Models\SiteSearchConsoleProperty;
use App\Services\ArticleIndexingService;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ArticleIndexingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->string('domain')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
        });
        foreach (['articles' => 'website_id', 'product_articles' => 'site_id'] as $table => $siteColumn) {
            Schema::create($table, function (Blueprint $t) use ($siteColumn, $table) {
                $table === 'product_articles' ? $t->string('id')->primary() : $t->id();
                $t->unsignedBigInteger($siteColumn);
                $t->string('title');
                $t->string('publish_status')->nullable();
                $t->string('wp_permalink')->nullable();
                $t->string('shopify_url')->nullable();
                $t->unsignedBigInteger('wp_post_id')->nullable();
                $t->timestamp('published_at')->nullable();
            });
        }
        Schema::create('site_wordpress_articles', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('wp_post_id');
            $t->string('url');
            $t->timestamp('published_at')->nullable();
        });
        Schema::create('google_search_console_connections', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->timestamps();
        });
        Schema::create('site_search_console_properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('google_search_console_connection_id');
            $t->string('property_url');
            $t->timestamps();
        });
        Schema::create('article_index_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->string('subject_type');
            $t->string('subject_id');
            $t->string('title');
            $t->text('url');
            $t->timestamp('published_at')->nullable();
            $t->boolean('is_indexed')->nullable();
            foreach (['verdict', 'coverage_state', 'indexing_state', 'robots_txt_state', 'page_fetch_state', 'google_canonical', 'inspection_link', 'error'] as $c) {
                $t->text($c)->nullable();
            }
            $t->timestamp('last_crawl_at')->nullable();
            $t->timestamp('first_indexed_at')->nullable();
            $t->unsignedSmallInteger('checks_count')->default(0);
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });

        DB::table('sites')->insert(['id' => 1, 'domain' => 'shop.ir']);
        DB::table('google_search_console_connections')->insert(['id' => 1, 'status' => 'connected']);
        DB::table('site_search_console_properties')->insert(['id' => 1, 'site_id' => 1, 'google_search_console_connection_id' => 1, 'property_url' => 'sc-domain:shop.ir']);
        DB::table('articles')->insert([
            ['id' => 1, 'website_id' => 1, 'title' => 'ایندکس‌شده', 'publish_status' => 'published', 'wp_permalink' => 'https://shop.ir/a', 'published_at' => now()->subDays(10)],
            ['id' => 2, 'website_id' => 1, 'title' => 'ناشناخته', 'publish_status' => 'published', 'wp_permalink' => 'https://blog.shop.ir/b', 'published_at' => now()->subDays(2)],
            ['id' => 3, 'website_id' => 1, 'title' => 'سایت دیگر', 'publish_status' => 'published', 'wp_permalink' => 'https://other.ir/c', 'published_at' => now()],
            ['id' => 4, 'website_id' => 1, 'title' => 'پیش‌نویس', 'publish_status' => 'draft', 'wp_permalink' => 'https://shop.ir/d', 'published_at' => null],
        ]);
        DB::table('product_articles')->insert(['id' => '01JPRODUCT', 'site_id' => 1, 'title' => 'مقاله محصول', 'publish_status' => 'published', 'wp_permalink' => null, 'shopify_url' => 'https://shop.ir/p', 'published_at' => now()->subDay()]);
    }

    private function service(callable $inspect): ArticleIndexingService
    {
        $gsc = Mockery::mock(GoogleSearchConsoleService::class);
        $gsc->shouldReceive('inspectUrl')->andReturnUsing(fn ($property, $url) => $inspect($url));

        return new ArticleIndexingService($gsc);
    }

    private function inspection(string $verdict, string $coverage): array
    {
        return ['verdict' => $verdict, 'coverage_state' => $coverage, 'indexing_state' => 'INDEXING_ALLOWED', 'robots_txt_state' => 'ALLOWED', 'page_fetch_state' => 'SUCCESSFUL', 'last_crawl_time' => '2026-10-30T08:00:00Z', 'google_canonical' => null, 'inspection_link' => 'https://search.google.com/x'];
    }

    public function test_inspection_api_response_is_parsed_and_quota_is_detected(): void
    {
        $connection = (new \App\Models\GoogleSearchConsoleConnection)->forceFill(['status' => 'connected', 'access_token' => 'token', 'expires_at' => now()->addHour()]);
        $property = (new SiteSearchConsoleProperty)->forceFill(['property_url' => 'sc-domain:shop.ir'])->setRelation('connection', $connection);

        \Illuminate\Support\Facades\Http::fake([
            'searchconsole.googleapis.com/*' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['inspectionResult' => [
                    'inspectionResultLink' => 'https://search.google.com/search-console/inspect?x',
                    'indexStatusResult' => ['verdict' => 'PASS', 'coverageState' => 'Submitted and indexed', 'lastCrawlTime' => '2026-10-30T08:00:00Z', 'pageFetchState' => 'SUCCESSFUL'],
                ]])
                ->push(['error' => ['message' => 'Quota exceeded']], 429),
        ]);

        $result = app(GoogleSearchConsoleService::class)->inspectUrl($property, 'https://shop.ir/a');
        $this->assertSame('PASS', $result['verdict']);
        $this->assertSame('Submitted and indexed', $result['coverage_state']);
        $this->assertSame('https://search.google.com/search-console/inspect?x', $result['inspection_link']);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request['siteUrl'] === 'sc-domain:shop.ir' && $request['inspectionUrl'] === 'https://shop.ir/a' && $request->hasHeader('Authorization', 'Bearer token'));

        $this->expectException(SearchConsoleQuotaException::class);
        app(GoogleSearchConsoleService::class)->inspectUrl($property, 'https://shop.ir/b');
    }

    public function test_url_must_belong_to_the_property(): void
    {
        $this->assertTrue(GoogleSearchConsoleService::urlBelongsToProperty('sc-domain:shop.ir', 'https://www.shop.ir/a'));
        $this->assertTrue(GoogleSearchConsoleService::urlBelongsToProperty('sc-domain:shop.ir', 'https://blog.shop.ir/a'));
        $this->assertFalse(GoogleSearchConsoleService::urlBelongsToProperty('sc-domain:shop.ir', 'https://myshop.ir/a'));
        $this->assertTrue(GoogleSearchConsoleService::urlBelongsToProperty('https://shop.ir/', 'https://shop.ir/blog/a'));
        $this->assertFalse(GoogleSearchConsoleService::urlBelongsToProperty('https://shop.ir/', 'https://www.shop.ir/blog/a'));
    }

    public function test_only_published_articles_are_tracked_and_unpublished_ones_removed(): void
    {
        $service = $this->service(fn () => $this->inspection('PASS', 'Submitted and indexed'));

        $this->assertSame(4, $service->syncSubjects(Site::find(1)));
        $this->assertSame(['1', '2', '3', '01JPRODUCT'], ArticleIndexCheck::orderBy('id')->pluck('subject_id')->all());

        DB::table('articles')->where('id', 3)->update(['publish_status' => 'draft']);
        $service->syncSubjects(Site::find(1));
        $this->assertFalse(ArticleIndexCheck::where('subject_id', '3')->exists());
    }

    public function test_check_records_google_status_and_skips_foreign_urls(): void
    {
        $service = $this->service(fn (string $url) => str_contains($url, 'shop.ir/a') || str_ends_with($url, '/p')
            ? $this->inspection('PASS', 'Submitted and indexed')
            : $this->inspection('NEUTRAL', 'URL is unknown to Google'));

        $stats = $service->checkProperty(SiteSearchConsoleProperty::find(1));

        $this->assertSame(['tracked' => 4, 'checked' => 3, 'indexed' => 2, 'errors' => 1, 'quota_reached' => false], $stats);
        $a = ArticleIndexCheck::where('subject_id', '1')->first();
        $this->assertTrue($a->is_indexed);
        $this->assertNotNull($a->first_indexed_at);
        $this->assertSame('Submitted and indexed', $a->coverageLabel());
        $this->assertFalse(ArticleIndexCheck::where('subject_id', '2')->first()->is_indexed);
        $this->assertStringContainsString('not under the Search Console property', ArticleIndexCheck::where('subject_id', '3')->first()->error);
    }

    public function test_quota_stops_the_run_and_recheck_schedule_is_respected(): void
    {
        $calls = 0;
        $service = $this->service(function () use (&$calls) {
            if (++$calls === 2) {
                throw new SearchConsoleQuotaException;
            }

            return $this->inspection('PASS', 'Submitted and indexed');
        });

        $stats = $service->checkProperty(SiteSearchConsoleProperty::find(1));
        $this->assertTrue($stats['quota_reached']);
        $this->assertSame(1, $stats['checked']);

        // ایندکس‌شده‌ی دو روز پیش: نوبتش نیست؛ ایندکس‌نشده‌ی ۳۰ ساعت پیش و ایندکس‌شده‌ی ۸ روز پیش: نوبتشان است
        ArticleIndexCheck::query()->update(['checked_at' => now()->subDays(2), 'is_indexed' => true]);
        ArticleIndexCheck::where('subject_id', '2')->update(['is_indexed' => false, 'checked_at' => now()->subHours(30)]);
        ArticleIndexCheck::where('subject_id', '01JPRODUCT')->update(['checked_at' => now()->subDays(8)]);

        $this->assertEqualsCanonicalizing(['2', '01JPRODUCT'], $service->due(Site::find(1))->pluck('subject_id')->all());
    }

    public function test_draft_published_later_in_wordpress_is_tracked_with_its_live_url(): void
    {
        DB::table('articles')->insert([
            ['id' => 5, 'website_id' => 1, 'title' => 'پیش‌نویسِ منتشرشده در وردپرس', 'publish_status' => 'draft', 'wp_permalink' => 'https://shop.ir/?p=55', 'wp_post_id' => 55, 'published_at' => null],
            ['id' => 6, 'website_id' => 1, 'title' => 'هنوز پیش‌نویس', 'publish_status' => 'draft', 'wp_permalink' => 'https://shop.ir/?p=66', 'wp_post_id' => 66, 'published_at' => null],
        ]);
        DB::table('site_wordpress_articles')->insert(['site_id' => 1, 'wp_post_id' => 55, 'url' => 'https://shop.ir/live-post', 'published_at' => now()->subDays(5)]);

        app(ArticleIndexingService::class)->syncSubjects(Site::find(1));

        $row = ArticleIndexCheck::where('subject_id', '5')->first();
        $this->assertSame('https://shop.ir/live-post', $row?->url);
        $this->assertNotNull($row->published_at);
        $this->assertNull(ArticleIndexCheck::where('subject_id', '6')->first());
    }

    public function test_check_all_queues_every_connected_site_on_background(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        DB::table('site_search_console_properties')->insert(['id' => 2, 'site_id' => 2, 'google_search_console_connection_id' => 2, 'property_url' => 'sc-domain:off.ir']);
        DB::table('google_search_console_connections')->insert(['id' => 2, 'status' => 'disconnected']);

        app(\App\Http\Controllers\Admin\AdminArticleIndexingController::class)->checkAll();

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CheckArticleIndexingJob::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CheckArticleIndexingJob::class, fn ($job) => $job->propertyId === 1 && $job->connection === 'redis-background');
    }

    public function test_not_indexed_reasons_are_plain_and_blockers_come_first(): void
    {
        $check = fn (array $attrs) => (new ArticleIndexCheck)->forceFill($attrs + ['is_indexed' => false, 'published_at' => now()->subDays(30)]);

        $this->assertSame('Blocked by robots.txt', $check(['robots_txt_state' => 'DISALLOWED', 'coverage_state' => 'Crawled - currently not indexed'])->reason()['title']);
        $this->assertSame('Article has a noindex tag', $check(['indexing_state' => 'BLOCKED_BY_META_TAG'])->reason()['title']);
        $crawled = $check(['coverage_state' => 'Crawled - currently not indexed'])->reason();
        $this->assertSame("Google crawled the page but didn't index it", $crawled['title']);
        $this->assertFalse($crawled['is_new']);
        $this->assertTrue($check(['coverage_state' => 'URL is unknown to Google', 'published_at' => now()->subDays(3)])->reason()['is_new']);
        $this->assertSame('Google check failed', $check(['is_indexed' => null, 'error' => 'timeout'])->reason()['title']);
        $this->assertNull($check(['is_indexed' => true])->reason());
    }

    public function test_admin_page_shows_site_summary_and_reasons(): void
    {
        $this->withoutVite();
        $this->service(fn ($url) => str_ends_with($url, '/a')
            ? $this->inspection('PASS', 'Submitted and indexed')
            : $this->inspection('NEUTRAL', 'Crawled - currently not indexed'))
            ->checkProperty(SiteSearchConsoleProperty::find(1));

        $html = app(\App\Http\Controllers\Admin\AdminArticleIndexingController::class)
            ->index(\Illuminate\Http\Request::create('/admin/indexing', 'GET', ['site_id' => 1]))
            ->render();

        $this->assertStringContainsString('مقاله‌های یوتیکس در', $html);
        $this->assertStringContainsString('مشاهده‌ی مقاله‌ها', $html);
        $this->assertStringContainsString('چرا ایندکس نشده‌اند؟', $html);
        $this->assertStringContainsString('Google crawled the page but didn', $html);
        $this->assertStringContainsString('match the Search Console property', $html);
    }
}
