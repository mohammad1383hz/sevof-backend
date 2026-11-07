<?php

namespace Tests\Unit;

use App\Models\ArticleRefresh;
use App\Models\SiteConnection;
use App\Models\WordpressArticle;
use App\Support\ContentAudit;
use App\Support\RefreshBlocks;
use App\Services\AI\ArticleWriterService;
use App\Services\AI\PastArticleRefreshService;
use App\Services\SubscriptionService;
use App\Services\Wp\PastPostsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PastArticleRefreshFlowTest extends TestCase
{
    private const CONTENT = "<p>مقدمه درباره‌ی گلدان.</p>\n\n<h2>جنس گلدان</h2>\n<figure><img src=\"https://shop.ir/a.jpg\" alt=\"\"></figure>\n<p>گلدان سرامیکی سنگین است و <a href=\"https://shop.ir/guide\">راهنما</a> را ببینید.</p>\n\n<h2>جمع‌بندی</h2>\n<p>پایان.</p>\n";

    /** @var array<int, array> درخواست‌هایی که به افزونه رفته */
    private array $updates = [];

    private array $chatCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('user_id');
            $t->string('language')->nullable();
            $t->float('language_confidence')->nullable();
            $t->json('crawled_pages')->nullable();
            $t->softDeletes();
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
            $t->timestamp('modified_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->json('categories')->nullable();
            $t->json('tags')->nullable();
            $t->timestamp('plugin_synced_at')->nullable();
            $t->json('audit')->nullable();
            $t->timestamp('audited_at')->nullable();
            $t->timestamps();
        });
        Schema::create('articles', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('website_id');
            $t->string('title');
            $t->string('focus_keyword')->nullable();
            $t->string('publish_status')->nullable();
            $t->text('wp_permalink')->nullable();
            $t->text('shopify_url')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        // داده‌ی سرچ کنسول (برای رقابت داخلی و اولویت لینک)
        Schema::create('search_console_page_metrics', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->char('page_hash', 40);
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
            $t->char('page_hash', 40)->nullable();
            $t->integer('clicks')->default(0);
            $t->integer('impressions')->default(0);
            $t->float('ctr')->default(0);
            $t->float('position')->nullable();
        });
        // صفحه‌هایی که به‌روزرسانی می‌تواند به آن‌ها لینک داخلی بدهد
        Schema::create('product_articles', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->unsignedBigInteger('site_id');
            $t->string('title');
            $t->string('publish_status')->nullable();
            $t->text('wp_permalink')->nullable();
            $t->text('shopify_url')->nullable();
            $t->timestamp('published_at')->nullable();
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
            $t->string('stage')->nullable();
            $t->timestamp('stage_started_at')->nullable();
            $t->string('trigger')->default('manual');
            $t->string('reason');
            foreach (['metrics', 'queries', 'before', 'after'] as $c) {
                $t->json($c)->nullable();
            }
            $t->text('summary')->nullable();
            $t->decimal('credit_used', 12, 4)->default(0);
            $t->text('error')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });
        Schema::create('token_usage_records', function (Blueprint $t) {
            $t->id();
            $t->decimal('credit', 12, 4)->default(0);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        DB::table('users')->insert(['id' => 1]);
        DB::table('sites')->insert(['id' => 1, 'user_id' => 1, 'language' => 'fa', 'language_confidence' => 1]);
        DB::table('site_wordpress_articles')->insert(['id' => 5, 'site_id' => 1, 'wp_post_id' => 77, 'title' => 'راهنمای گلدان', 'url' => 'https://shop.ir/pot', 'managed' => false, 'editable' => true]);
    }

    /** هوش مصنوعی ساختگی: اول برنامه، بعد بازنویسی بخش با همان نشانه‌هایی که گرفته */
    private function service(array $remote, array $updateResults = []): PastArticleRefreshService
    {
        $writer = Mockery::mock(ArticleWriterService::class);
        $writer->shouldReceive('chat')->andReturnUsing(function (...$args) {
            $this->chatCalls[] = $args;

            // اولین فراخوانی همان فراخوانی واحد ویرایش است؛ بعدی (اگر باشد) اصلاح جمله‌هاست
            return count($this->chatCalls) === 1 ? $this->edits() : ['lines' => []];
        });

        $subscriptions = Mockery::mock(SubscriptionService::class);
        $subscriptions->shouldReceive('hasAvailableCredit')->andReturn(true);
        $subscriptions->shouldReceive('hasActivePaidPlan')->andReturn(true);

        $posts = Mockery::mock(PastPostsService::class);
        $posts->shouldReceive('connection')->andReturn(new SiteConnection);
        $posts->shouldReceive('fetch')->andReturn($remote);
        $posts->shouldReceive('storeAudit')->andReturnUsing(fn ($post, $remote, $domain) => ContentAudit::audit((string) $remote['content'], RefreshBlocks::HTML, $domain));
        $posts->shouldReceive('update')->andReturnUsing(function ($connection, $id, $payload) use (&$updateResults) {
            $this->updates[] = $payload;

            return array_shift($updateResults) ?? ['ok' => true, 'body' => ['modified' => '2026-11-02 10:00:00']];
        });

        $this->app->instance(ArticleWriterService::class, $writer);
        $this->app->instance(SubscriptionService::class, $subscriptions);
        $this->app->instance(PastPostsService::class, $posts);

        return $this->app->make(PastArticleRefreshService::class);
    }

    private function remote(array $overrides = []): array
    {
        return $overrides + [
            'editable' => true,
            'content' => self::CONTENT,
            'modified' => '2025-01-01 08:00:00',
            'seo' => ['plugin' => 'yoast', 'meta_title' => '', 'meta_description' => 'توضیح قدیمی'],
        ];
    }

    private function refresh(): ArticleRefresh
    {
        return ArticleRefresh::create([
            'site_id' => 1, 'source' => ArticleRefresh::SOURCE_WP_POST, 'wordpress_article_id' => 5,
            'status' => ArticleRefresh::STATUS_QUEUED, 'reason' => ArticleRefresh::REASON_MANUAL, 'queries' => [],
        ]);
    }

    /**
     * پاسخ یک‌باره‌ی AI: پاراگراف B2 (بخش «جنس گلدان» با تیتر، تصویر و لینک قفل‌شده) را با همان
     * نشانه‌ها کامل‌تر می‌کند؛ نشانه‌ها را از خود متنی که به AI رفته برمی‌دارد.
     */
    private function edits(): array
    {
        $prompt = (string) ($this->chatCalls[0][2] ?? ''); // chat(model, system, user, ...)
        preg_match('/\[B2\] (.*?)(?=\n\[B\d+\]|\n## |\z)/s', $prompt, $m);

        return [
            'summary' => 'بخش جنس گلدان کامل‌تر شد.',
            'meta_title' => 'گلدان سرامیکی؛ راهنمای انتخاب جنس و اندازه برای خانه',
            'meta_description' => 'قبل از خرید گلدان بدانید کدام جنس برای گیاه شما بهتر است و چرا سوراخ زهکشی برای سلامت ریشه مهم است.',
            'edits' => [
                ['block' => 2, 'action' => 'replace', 'content' => str_replace('سنگین است و', 'سنگین است، پس سوراخ زهکشی مهم است و', $m[1] ?? ''), 'why' => 'سوراخ زهکشی'],
                ['block' => 3, 'action' => 'insert_after', 'content' => '<p>یک پاراگراف تازه بدون لینک.</p>', 'why' => 'کامل‌تر شدن'],
            ],
        ];
    }

    public function test_generate_rewrites_html_keeping_images_links_and_other_sections(): void
    {
        $service = $this->service($this->remote());

        $refresh = $this->refresh();
        $service->generate($refresh);
        $refresh->refresh();

        $this->assertSame(ArticleRefresh::STATUS_READY, $refresh->status);
        $this->assertSame([1, 2], array_column($refresh->after['sections'], 'id'));
        // پاراگراف تازه بعد از متن قبلی بخش آخر، با همان خط آخر
        $this->assertSame("<h2>جمع‌بندی</h2>\n<p>پایان.</p>\n\n<p>یک پاراگراف تازه بدون لینک.</p>\n", $refresh->after['sections'][1]['content']);
        $new = $refresh->after['sections'][0]['content'];
        // نسخه‌ی نمایشی پاراگراف تغییرکرده را برجسته می‌کند؛ متن قبلی همان پاراگراف هم برای مقایسه هست
        $this->assertStringContainsString('<div data-yx-edit="replace" data-yx-n="1">', $refresh->after['sections'][0]['display']);
        $this->assertStringNotContainsString('data-yx-', $new);
        $this->assertSame(1, $refresh->after['sections'][0]['edits'][0]['n']);
        $this->assertStringContainsString('<a href="https://shop.ir/guide">', $refresh->after['sections'][0]['edits'][0]['before']);
        $this->assertSame('insert_after', $refresh->after['sections'][1]['edits'][0]['action']);
        $this->assertStringContainsString('<img src="https://shop.ir/a.jpg"', $new);
        $this->assertStringContainsString('<a href="https://shop.ir/guide">', $new);
        $this->assertStringContainsString('سوراخ زهکشی', $new);
        $this->assertStringContainsString('<h2>جنس گلدان</h2>', $new);
        $this->assertSame(self::CONTENT, $refresh->before['content']);
        $this->assertArrayHasKey('meta_title', $refresh->after['meta']);

        // تا تأیید کاربر چیزی به سایت فرستاده نمی‌شود
        $this->assertSame([], $this->updates);
    }

    public function test_page_builder_post_never_reaches_ai(): void
    {
        $service = $this->service($this->remote(['editable' => false, 'not_editable_reason' => 'elementor']));

        try {
            $service->generate($this->refresh());
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('المنتور', $e->getMessage());
        }

        $this->assertSame([], $this->chatCalls);
        $this->assertFalse(WordpressArticle::find(5)->editable);
    }

    public function test_apply_and_revert_send_exact_content_with_concurrency_guard(): void
    {
        $service = $this->service($this->remote());

        $refresh = $this->refresh();
        $service->generate($refresh);
        $refresh->refresh()->update(['status' => ArticleRefresh::STATUS_APPLYING]);
        $service->apply($refresh);

        $sent = $this->updates[0];
        $this->assertStringNotContainsString('data-yx-', $sent['content']);
        $this->assertSame('2025-01-01 08:00:00', $sent['expected_modified']);
        // بخش‌های دست‌نخورده بایت‌به‌بایت همان‌اند
        $this->assertStringStartsWith("<p>مقدمه درباره‌ی گلدان.</p>\n\n<h2>جنس گلدان</h2>", $sent['content']);
        $this->assertStringEndsWith("<h2>جمع‌بندی</h2>\n<p>پایان.</p>\n\n<p>یک پاراگراف تازه بدون لینک.</p>\n", $sent['content']);
        $this->assertStringContainsString('سوراخ زهکشی', $sent['content']);
        $this->assertSame(ArticleRefresh::STATUS_APPLIED, $refresh->fresh()->status);

        $refresh = $refresh->fresh();
        $refresh->update(['status' => ArticleRefresh::STATUS_APPLYING]);
        $service->revert($refresh);

        $back = $this->updates[1];
        $this->assertSame(self::CONTENT, $back['content']);
        $this->assertSame('2026-11-02 10:00:00', $back['expected_modified']);
        $this->assertSame('', $back['meta_title']);
        $this->assertSame(ArticleRefresh::STATUS_REVERTED, $refresh->fresh()->status);
    }

    public function test_apply_refuses_when_post_was_edited_in_wordpress(): void
    {
        $service = $this->service($this->remote(), [['ok' => false, 'status_code' => 409, 'message' => 'conflict']]);

        $refresh = $this->refresh();
        $service->generate($refresh);
        $refresh->refresh()->update(['status' => ArticleRefresh::STATUS_APPLYING]);
        $service->apply($refresh);

        $this->assertSame(ArticleRefresh::STATUS_READY, $refresh->fresh()->status);
        $this->assertStringContainsString('در وردپرس ویرایش شده', $refresh->fresh()->error);
    }
}
