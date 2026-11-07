<?php

namespace Tests\Unit;

use App\Models\ArticleIdea;
use App\Models\ArticleRefresh;
use App\Models\Site;
use App\Services\AI\ArticleRefreshService;
use App\Services\RelatedArticleLinkService;
use App\Services\SearchConsoleInsightsService;
use App\Support\PageUrl;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SearchConsoleInsightsTest extends TestCase
{
    private SearchConsoleInsightsService $insights;

    protected function setUp(): void
    {
        parent::setUp();

        // config کش‌شده است؛ برای اطمینان صریحاً به SQLite در حافظه می‌رویم (نه دیتابیس واقعی)
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');
        $this->assertSame('sqlite', DB::connection()->getDriverName());

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
        Schema::create('search_console_query_metrics', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->string('query');
            $t->string('page')->nullable();
            $t->string('page_hash')->nullable();
            $t->integer('clicks');
            $t->integer('impressions');
            $t->float('ctr')->default(0);
            $t->float('position')->nullable();
        });
        foreach (['articles' => 'website_id', 'product_articles' => 'site_id'] as $table => $siteColumn) {
            Schema::create($table, function (Blueprint $t) use ($siteColumn) {
                $t->id();
                $t->unsignedBigInteger($siteColumn);
                $t->string('title');
                $t->string('focus_keyword')->nullable();
                $t->string('status')->default('completed');
                $t->string('publish_status')->nullable();
                $t->string('wp_permalink')->nullable();
                $t->string('shopify_url')->nullable();
                $t->timestamp('published_at')->nullable();
            });
        }
        Schema::create('article_refreshes', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('article_id');
            $t->string('status');
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });

        $this->insights = new SearchConsoleInsightsService;
    }

    private function site(): Site
    {
        return (new Site)->forceFill(['id' => 1]);
    }

    private function article(int $id, string $url, int $ageDays, string $title = 'مقاله'): void
    {
        DB::table('articles')->insert(['id' => $id, 'website_id' => 1, 'title' => $title, 'publish_status' => 'published', 'wp_permalink' => $url, 'published_at' => now()->subDays($ageDays)]);
    }

    private function pageMetric(string $url, int $clicks, int $impressions, ?float $position, ?int $previousClicks = null): void
    {
        DB::table('search_console_page_metrics')->insert([
            'site_id' => 1, 'page_hash' => PageUrl::hash($url), 'clicks' => $clicks, 'impressions' => $impressions,
            'position' => $position, 'previous_clicks' => $previousClicks, 'previous_impressions' => $previousClicks !== null ? $impressions : null,
        ]);
    }

    public function test_page_url_variants_get_the_same_key(): void
    {
        $this->assertSame(
            PageUrl::hash('https://www.Shop.ir/blog/%D8%B3%D8%A6%D9%88/'),
            PageUrl::hash('http://shop.ir/blog/سئو?utm_source=x#top'),
        );
        $this->assertNotSame(PageUrl::hash('https://shop.ir/a'), PageUrl::hash('https://shop.ir/b'));
        $this->assertNull(PageUrl::hash(''));
    }

    public function test_refresh_reason_rules(): void
    {
        $row = fn (array $o) => $o + ['clicks' => 5, 'previous_clicks' => null, 'impressions' => 400, 'position' => 12.0];

        $this->assertSame(ArticleRefresh::REASON_DECLINING, $this->insights->refreshReason($row(['clicks' => 10, 'previous_clicks' => 40]))['reason']);
        $this->assertSame(ArticleRefresh::REASON_NEAR_FIRST_PAGE, $this->insights->refreshReason($row([]))['reason']);
        $this->assertSame(ArticleRefresh::REASON_LOW_CTR, $this->insights->refreshReason($row(['position' => 2.0, 'clicks' => 4]))['reason']);
        $this->assertNull($this->insights->refreshReason($row(['position' => 2.0, 'clicks' => 80])));
        $this->assertNull($this->insights->refreshReason($row(['impressions' => 10])));
        $this->assertSame(15, $this->insights->refreshReason($row(['impressions' => 400]))['potential_clicks']);
    }

    public function test_page_metrics_merge_url_variants_with_weighted_position(): void
    {
        $this->pageMetric('https://shop.ir/a/', 10, 100, 4.0);
        $this->pageMetric('http://www.shop.ir/a', 0, 300, 12.0);

        $m = $this->insights->pageMetrics($this->site())->get(PageUrl::hash('shop.ir/a'));

        $this->assertSame(10, $m['clicks']);
        $this->assertSame(400, $m['impressions']);
        $this->assertSame(10.0, $m['position']);
    }

    public function test_refresh_candidates_skip_new_and_busy_articles(): void
    {
        $this->article(1, 'https://shop.ir/old', 40, 'قدیمی');
        $this->article(2, 'https://shop.ir/new', 5, 'تازه');
        $this->article(3, 'https://shop.ir/busy', 40, 'در حال کار');
        foreach (['old', 'new', 'busy'] as $slug) {
            $this->pageMetric("https://shop.ir/{$slug}", 3, 500, 11.0);
        }
        DB::table('article_refreshes')->insert(['site_id' => 1, 'article_id' => 3, 'status' => ArticleRefresh::STATUS_READY]);

        $candidates = $this->insights->refreshCandidates($this->site());

        $this->assertSame([1], $candidates->pluck('id')->all());
        $this->assertSame(ArticleRefresh::REASON_NEAR_FIRST_PAGE, $candidates->first()['reason']);
    }

    public function test_idea_evidence_lists_real_gaps_and_skips_covered_searches(): void
    {
        $rows = [
            ['خرید گلدان سرامیکی', 120, 14.0],
            ['نگهداری کاکتوس', 90, 3.0],
            ['آموزش کاشت پیاز گل', 60, 18.0],
        ];
        foreach ($rows as [$query, $impressions, $position]) {
            DB::table('search_console_query_metrics')->insert(['site_id' => 1, 'query' => $query, 'clicks' => 1, 'impressions' => $impressions, 'position' => $position]);
        }

        $block = $this->insights->ideaEvidenceBlock($this->site(), ['راهنمای آموزش کاشت پیاز گل در خانه']);

        $this->assertStringContainsString('خرید گلدان سرامیکی | impressions: 120', $block);
        $this->assertStringNotContainsString('آموزش کاشت پیاز گل |', $block);
        $this->assertMatchesRegularExpression('/B\).*\n- نگهداری کاکتوس/u', $block);
    }

    public function test_competing_pages_find_cannibalization_and_the_main_page(): void
    {
        $rows = [
            // دو صفحه‌ی واقعاً رقیب: صفحه‌ی b کلیک بیشتری دارد و صفحه‌ی اصلی است
            ['خرید گلدان سرامیکی', 'https://shop.ir/a', 'ha', 1, 120, 9.0],
            ['خرید گلدان سرامیکی', 'https://shop.ir/b', 'hb', 6, 90, 6.0],
            // سهم ناچیز صفحه‌ی دوم: رقابت حساب نمی‌شود
            ['نگهداری کاکتوس', 'https://shop.ir/c', 'hc', 4, 200, 3.0],
            ['نگهداری کاکتوس', 'https://shop.ir/d', 'hd', 0, 6, 40.0],
        ];
        foreach ($rows as [$query, $page, $hash, $clicks, $impressions, $position]) {
            DB::table('search_console_query_metrics')->insert(compact('query', 'page', 'clicks', 'impressions', 'position') + ['site_id' => 1, 'page_hash' => $hash]);
        }

        $competing = $this->insights->competingPages($this->site());

        $this->assertSame(['خرید گلدان سرامیکی'], $competing->pluck('query')->all());
        $this->assertSame(['hb', 'ha'], array_column($competing[0]['pages'], 'hash'));
        $this->assertSame(['خرید گلدان سرامیکی' => 'https://shop.ir/b'], $this->insights->competitorsForPage($this->site(), 'ha'));
        $this->assertSame([], $this->insights->competitorsForPage($this->site(), 'hb'));
        $this->assertStringContainsString("C) Already contested", $this->insights->ideaEvidenceBlock($this->site()));
    }

    public function test_question_searches_are_recognised(): void
    {
        $this->assertTrue(SearchConsoleInsightsService::isQuestion('گلدان سرامیکی چیست'));
        $this->assertTrue(SearchConsoleInsightsService::isQuestion('چطور کاکتوس را آب بدهیم'));
        $this->assertTrue(SearchConsoleInsightsService::isQuestion('how to repot a cactus'));
        $this->assertFalse(SearchConsoleInsightsService::isQuestion('خرید گلدان سرامیکی'));
    }

    public function test_refresh_masks_links_so_ai_cannot_break_them(): void
    {
        $service = $this->app->make(ArticleRefreshService::class);
        [$masked, $urls] = $service->maskLinks('اول [این](https://a.ir/x) و [آن](https://b.ir/y) و باز [این](https://a.ir/x).');

        $this->assertSame('اول [این](LINK_1) و [آن](LINK_2) و باز [این](LINK_1).', $masked);
        $this->assertSame(['LINK_1' => 'https://a.ir/x', 'LINK_2' => 'https://b.ir/y'], $urls);
    }

    public function test_link_anchor_prefers_search_closest_to_idea(): void
    {
        $service = $this->app->make(RelatedArticleLinkService::class);
        $idea = (new ArticleIdea)->forceFill(['title' => 'راهنمای انتخاب گلدان سرامیکی برای آپارتمان', 'outline' => []]);

        $anchor = (fn () => $this->anchorQuery($idea, ['خرید گل طبیعی', 'گلدان سرامیکی آپارتمانی']))->call($service);

        $this->assertSame('گلدان سرامیکی آپارتمانی', $anchor);
        $this->assertNull((fn () => $this->anchorQuery($idea, []))->call($service));
    }
}
