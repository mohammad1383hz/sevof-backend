<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\AI\ArticleIdeaService;
use App\Services\Products\ProductSeoIdeaService;
use App\Services\TopicHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** موضوع تازه نباید تکرار مقاله‌های خود سایت یا ایده‌های قبلی باشد؛ عنوان ماشینی کنار گذاشته می‌شود. */
class TopicHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('user_id');
            $t->string('domain')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('site_wordpress_articles', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->string('title');
            $t->boolean('managed')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('article_ideas', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('website_id');
            $t->string('title');
            $t->timestamps();
        });
        Schema::create('product_seo_ideas', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->string('title');
            $t->timestamps();
        });

        DB::table('sites')->insert(['id' => 1, 'user_id' => 1, 'domain' => 'shop.ir']);
        $posts = [];
        foreach (range(1, 7) as $i) {
            $posts[] = ['site_id' => 1, 'title' => "پست شماره {$i} درباره گلدان", 'managed' => false, 'published_at' => "2026-0{$i}-01"];
        }
        $posts[] = ['site_id' => 1, 'title' => 'زمان کاشت گوجه فرنگی', 'managed' => false, 'published_at' => '2025-01-01'];
        // مقاله‌ی خود یوتیکس در فهرست «مقاله‌های خود سایت» نمی‌آید
        $posts[] = ['site_id' => 1, 'title' => 'مقاله‌ی یوتیکس', 'managed' => true, 'published_at' => '2026-10-01'];
        DB::table('site_wordpress_articles')->insert($posts);
        DB::table('product_seo_ideas')->insert(['site_id' => 1, 'title' => 'قیمت هدست گیمینگ JBL Quantum + خرید اصل']);
    }

    public function test_recent_posts_block_has_the_last_five_site_posts_only(): void
    {
        $block = app(TopicHistory::class)->recentPostsBlock(Site::find(1));

        $this->assertStringContainsString('پست شماره 7', $block);
        $this->assertStringContainsString('پست شماره 3', $block);
        $this->assertStringNotContainsString('پست شماره 2', $block);
        $this->assertStringNotContainsString('مقاله‌ی یوتیکس', $block);
    }

    public function test_article_idea_repeating_a_site_post_or_robotic_title_is_dropped(): void
    {
        $service = app(ArticleIdeaService::class);
        $kept = (fn () => $this->filterDuplicateIdeas(Site::find(1), [
            ['title' => 'زمان کاشت گوجه فرنگی: راهنمای کامل'],
            ['title' => 'گوجه فرنگی از صفر تا صد'],
            ['title' => 'اشتباهات رایج در آبیاری گوجه'],
        ]))->call($service);

        $this->assertSame(['اشتباهات رایج در آبیاری گوجه'], array_column($kept, 'title'));
    }

    public function test_product_idea_repeating_a_previous_one_is_dropped_and_all_duplicates_fail(): void
    {
        $service = app(ProductSeoIdeaService::class);
        $call = fn (array $ideas) => (fn () => $this->dropDuplicateIdeas(Site::find(1), $ideas))->call($service);

        $kept = $call([
            ['title' => 'قیمت هدست گیمینگ JBL Quantum + خرید'],
            ['title' => 'راهنمای خرید هدست گیمینگ JBL Quantum برای گیمرها'],
        ]);
        $this->assertSame(['راهنمای خرید هدست گیمینگ JBL Quantum برای گیمرها'], array_column($kept, 'title'));

        $this->expectException(RuntimeException::class);
        $call([['title' => 'قیمت هدست گیمینگ JBL Quantum']]);
    }
}
