<?php

namespace Tests\Unit;

use App\Models\ArticleRefresh;
use App\Services\AI\ArticleRefreshService;
use App\Services\AI\ArticleWriterService;
use App\Services\ArticlePublisherResolver;
use App\Services\SubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ArticleRefreshFlowTest extends TestCase
{
    /** پیام‌هایی که به AI فرستاده شد */
    private array $prompts = [];

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
            $t->string('domain')->default('shop.ir');
            $t->string('language')->nullable();
            $t->float('language_confidence')->nullable();
            $t->json('crawled_pages')->nullable();
            $t->softDeletes();
        });
        Schema::create('agent_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->string('default_publish_status')->default('draft');
        });
        Schema::create('articles', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('website_id');
            $t->string('title');
            $t->string('focus_keyword')->nullable();
            $t->string('meta_title')->nullable();
            $t->string('meta_description')->nullable();
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
        Schema::create('site_wordpress_articles', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->string('title');
            $t->text('url');
            $t->json('categories')->nullable();
            $t->json('tags')->nullable();
            $t->boolean('managed')->nullable();
            $t->timestamp('plugin_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('article_sections', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('article_id');
            $t->integer('position');
            $t->string('type');
            $t->string('heading');
            $t->text('content');
            $t->timestamps();
        });
        Schema::create('article_refreshes', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            // همان ستون‌های جدول واقعی (Eloquent فهرست ستون‌ها را بین تست‌ها نگه می‌دارد)
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
        DB::table('articles')->insert([
            ['id' => 7, 'website_id' => 1, 'title' => 'راهنمای خرید گلدان', 'meta_title' => 'عنوان قدیمی', 'meta_description' => 'توضیح قدیمی', 'publish_status' => null, 'wp_permalink' => null],
            // مقاله‌ی منتشرشده‌ی دیگر یوتیکس که می‌شود به آن لینک داد
            ['id' => 8, 'website_id' => 1, 'title' => 'انتخاب خاک مناسب برای گلدان سرامیکی', 'meta_title' => null, 'meta_description' => null, 'publish_status' => 'published', 'wp_permalink' => 'https://shop.ir/soil'],
        ]);
        DB::table('site_wordpress_articles')->insert(['site_id' => 1, 'title' => 'آبیاری گیاهان آپارتمانی', 'url' => 'https://shop.ir/watering', 'managed' => false]);
        DB::table('article_sections')->insert([
            ['id' => 10, 'article_id' => 7, 'position' => 1, 'type' => 'introduction', 'heading' => 'مقدمه', 'content' => 'گلدان خوب مهم است. [راهنما](https://shop.ir/guide) را ببینید.'],
            ['id' => 11, 'article_id' => 7, 'position' => 2, 'type' => 'section', 'heading' => 'جنس گلدان', 'content' => 'گلدان سرامیکی سنگین است و دیر خشک می‌شود.'],
        ]);
    }

    private function refresh(): ArticleRefresh
    {
        return ArticleRefresh::create([
            'site_id' => 1,
            'article_id' => 7,
            'status' => ArticleRefresh::STATUS_QUEUED,
            'reason' => ArticleRefresh::REASON_NEAR_FIRST_PAGE,
            'queries' => [['query' => 'خرید گلدان سرامیکی', 'impressions' => 300, 'clicks' => 2, 'position' => 12.0]],
            'before' => [
                'meta' => ['meta_title' => 'عنوان قدیمی', 'meta_description' => 'توضیح قدیمی'],
                'sections' => DB::table('article_sections')->get(['id', 'position', 'type', 'heading', 'content'])->map(fn ($s) => (array) $s)->all(),
            ],
        ]);
    }

    private function service(array $responses, bool $publishOk = true): ArticleRefreshService
    {
        $writer = Mockery::mock(ArticleWriterService::class);
        $writer->shouldReceive('chat')->andReturnUsing(function (...$args) use (&$responses) {
            $this->prompts[] = (string) ($args['user'] ?? $args[2] ?? '');

            return array_shift($responses);
        });

        $subscriptions = Mockery::mock(SubscriptionService::class);
        $subscriptions->shouldReceive('hasAvailableCredit')->andReturn(true);
        $subscriptions->shouldReceive('hasActivePaidPlan')->andReturn(true);
        $this->app->instance(SubscriptionService::class, $subscriptions);
        $this->app->instance(ArticleWriterService::class, $writer);

        $publisher = Mockery::mock(\App\Contracts\ArticlePublisher::class);
        $publisher->shouldReceive('update')->andReturn(['ok' => $publishOk, 'message' => 'سایت در دسترس نیست']);
        $resolver = Mockery::mock(ArticlePublisherResolver::class);
        $resolver->shouldReceive('for')->andReturn($publisher);
        $this->app->instance(ArticlePublisherResolver::class, $resolver);

        return $this->app->make(ArticleRefreshService::class);
    }

    /** پاسخ یک‌باره‌ی AI: B1 = پاراگراف مقدمه (بخش ۱۰)، B2 = پاراگراف بخش ۱۱ */
    private function edits(string $intro, string $material): array
    {
        return [
            'summary' => 'مقدمه و بخش جنس گلدان کامل‌تر شد.',
            'meta_title' => 'خرید گلدان سرامیکی؛ راهنمای انتخاب برای خانه و آپارتمان',
            'meta_description' => 'قبل از خرید گلدان سرامیکی بدانید کدام جنس برای گیاه شما بهتر است، چطور آب اضافه را خارج کنید و چه اندازه‌ای بخرید.',
            'edits' => [
                ['block' => 1, 'action' => 'replace', 'content' => $intro, 'why' => 'جواب مستقیم به جستجوی خرید گلدان سرامیکی'],
                ['block' => 2, 'action' => 'replace', 'content' => $material, 'why' => 'توضیح سوراخ زهکشی'],
            ],
        ];
    }

    public function test_section_whose_rewrite_drops_a_link_is_left_unchanged(): void
    {
        $service = $this->service([
            $this->edits('اگر دنبال خرید گلدان سرامیکی هستید، اول جنس و اندازه را ببینید.', 'گلدان سرامیکی سنگین است و دیر خشک می‌شود. حتماً سوراخ زهکشی داشته باشد تا آب اضافه بیرون برود.'),
        ]);
        $refresh = $this->refresh();

        $service->generate($refresh);

        $this->assertSame([11], array_column($refresh->fresh()->after['sections'], 'id'));
    }

    public function test_generate_keeps_links_and_rejects_a_too_short_rewrite(): void
    {
        $service = $this->service([
            $this->edits('اگر دنبال خرید گلدان سرامیکی هستید، اول جنس را ببینید. [راهنما](LINK_1) کمک می‌کند.', 'کوتاه'),
        ]);
        $refresh = $this->refresh();

        $service->generate($refresh);
        $refresh->refresh();

        $this->assertSame(ArticleRefresh::STATUS_READY, $refresh->status);
        $this->assertCount(1, $refresh->after['sections']);
        $this->assertSame(10, $refresh->after['sections'][0]['id']);
        $this->assertStringContainsString('(https://shop.ir/guide)', $refresh->after['sections'][0]['content']);
        $this->assertArrayHasKey('meta_title', $refresh->after['meta']);
        // تا تأیید کاربر، مقاله‌ی اصلی دست نمی‌خورد
        $this->assertStringStartsWith('گلدان خوب مهم است.', DB::table('article_sections')->where('id', 10)->value('content'));
    }

    public function test_new_internal_link_goes_only_to_a_related_page_and_prompt_knows_today(): void
    {
        $service = $this->service([
            $this->edits(
                'گلدان خوب مهم است. [راهنما](LINK_1) را ببینید. [[L2|بی‌ربط]] [[L1|اینجا]]',
                'گلدان سرامیکی سنگین است و دیر خشک می‌شود. برای پر کردنش راهنمای [[L1|انتخاب خاک مناسب گلدان]] را هم بخوانید و [[L1|خاک گلدان سرامیکی]] را دوباره نبینید.',
            ),
        ]);
        $refresh = $this->refresh();

        $service->generate($refresh);
        $after = $refresh->fresh()->after;
        $material = collect($after['sections'])->firstWhere('id', 11)['content'];

        $this->assertStringContainsString('[انتخاب خاک مناسب گلدان](https://shop.ir/soil)', $material);
        // هر صفحه یک بار؛ نشانه‌ی دوم و نشانه‌های نامعتبر به متن ساده برمی‌گردند
        $this->assertSame(1, substr_count($material, 'https://shop.ir/soil'));
        $this->assertStringNotContainsString('[[', json_encode($after, JSON_UNESCAPED_UNICODE));
        $this->assertSame([['url' => 'https://shop.ir/soil', 'anchor' => 'انتخاب خاک مناسب گلدان']], array_map(fn ($l) => ['url' => $l['url'], 'anchor' => $l['anchor']], $after['links']));

        $prompt = implode("\n", $this->prompts);
        $this->assertStringContainsString('TODAY:', $prompt);
        $this->assertStringContainsString('[L1] انتخاب خاک مناسب برای گلدان سرامیکی', $prompt);
        // صفحه‌ی بی‌ربط (آبیاری) در فهرست نیست
        $this->assertStringNotContainsString('آبیاری', $prompt);
    }

    public function test_apply_publishes_and_revert_restores_previous_version(): void
    {
        $service = $this->service([
            $this->edits('اگر دنبال خرید گلدان سرامیکی هستید، اول جنس را ببینید. [راهنما](LINK_1) کمک می‌کند.', 'کوتاه'),
        ]);
        $refresh = $this->refresh();
        $service->generate($refresh);

        $refresh->refresh()->update(['status' => ArticleRefresh::STATUS_APPLYING]);
        $service->apply($refresh);

        $this->assertSame(ArticleRefresh::STATUS_APPLIED, $refresh->fresh()->status);
        $this->assertStringContainsString('خرید گلدان سرامیکی', DB::table('article_sections')->where('id', 10)->value('content'));
        $this->assertSame('خرید گلدان سرامیکی؛ راهنمای انتخاب برای خانه و آپارتمان', DB::table('articles')->where('id', 7)->value('meta_title'));

        $refresh = $refresh->fresh();
        $refresh->update(['status' => ArticleRefresh::STATUS_APPLYING]);
        $service->revert($refresh);

        $this->assertSame(ArticleRefresh::STATUS_REVERTED, $refresh->fresh()->status);
        $this->assertStringStartsWith('گلدان خوب مهم است.', DB::table('article_sections')->where('id', 10)->value('content'));
        $this->assertSame('عنوان قدیمی', DB::table('articles')->where('id', 7)->value('meta_title'));
    }

    public function test_apply_never_overwrites_manual_edits_and_rolls_back_on_publish_failure(): void
    {
        $service = $this->service([
            $this->edits('اگر دنبال خرید گلدان سرامیکی هستید، اول جنس را ببینید. [راهنما](LINK_1) کمک می‌کند.', 'کوتاه'),
        ], publishOk: false);
        $refresh = $this->refresh();
        $service->generate($refresh);

        // انتشار شکست می‌خورد → مقاله‌ی داخلی به نسخه‌ی قبل برمی‌گردد و پیشنهاد برای تلاش دوباره می‌ماند
        $refresh->refresh()->update(['status' => ArticleRefresh::STATUS_APPLYING]);
        $service->apply($refresh);
        $this->assertSame(ArticleRefresh::STATUS_READY, $refresh->fresh()->status);
        $this->assertStringContainsString('سایت در دسترس نیست', $refresh->fresh()->error);
        $this->assertStringStartsWith('گلدان خوب مهم است.', DB::table('article_sections')->where('id', 10)->value('content'));

        // کاربر مقاله را دستی ویرایش کرد → apply هیچ چیزی را بازنویسی نمی‌کند
        DB::table('article_sections')->where('id', 10)->update(['content' => 'متن ویرایش‌شده‌ی کاربر']);
        $refresh = $refresh->fresh();
        $refresh->update(['status' => ArticleRefresh::STATUS_APPLYING]);
        $service->apply($refresh);
        $this->assertSame(ArticleRefresh::STATUS_READY, $refresh->fresh()->status);
        $this->assertSame('متن ویرایش‌شده‌ی کاربر', DB::table('article_sections')->where('id', 10)->value('content'));
    }
}
