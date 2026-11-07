<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** شناسه‌ی عمومی (token) به‌جای آی‌دی عددی در آدرس و خروجی؛ آی‌دی عددی داخل دیتابیس می‌ماند. */
class PublicTokenTest extends TestCase
{
    private const TABLES = [
        'sites', 'articles', 'article_ideas', 'article_sections', 'article_refreshes', 'site_wordpress_articles',
        'product_seo_ideas', 'page_product_seo_optimizations', 'content_research_sessions', 'content_chat_messages',
        'tickets', 'plans', 'api_agents', 'page_visits',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        foreach (self::TABLES as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                if ($table === 'sites') {
                    $t->unsignedBigInteger('user_id');
                    $t->string('domain')->nullable();
                    $t->softDeletes();
                }
                $t->timestamps();
            });
        }
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_11_04_000001_add_public_tokens.php'))->up();
    }

    public function test_existing_rows_get_unique_tokens_and_ids_stay(): void
    {
        DB::table('users')->insert(['id' => 1]);
        foreach (range(1, 1005) as $i) {
            $rows[] = ['id' => $i, 'user_id' => 1, 'domain' => "s{$i}.ir"];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('sites')->insert($chunk);
        }

        $this->migrate();

        $tokens = DB::table('sites')->orderBy('id')->pluck('token', 'id');
        $this->assertCount(1005, $tokens);
        $this->assertSame(range(1, 1005), $tokens->keys()->all());
        $this->assertCount(1005, $tokens->unique());
        $tokens->each(fn ($token) => $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $token));

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'token'), $table);
        }

        // اجرای دوباره چیزی را عوض نمی‌کند
        $this->migrate();
        $this->assertSame($tokens->all(), DB::table('sites')->orderBy('id')->pluck('token', 'id')->all());
    }

    public function test_new_model_gets_token_and_json_id_is_the_token(): void
    {
        $this->migrate();
        DB::table('users')->insert(['id' => 1]);

        $site = Site::create(['user_id' => 1, 'domain' => 'shop.ir']);

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $site->token);
        $this->assertSame($site->token, $site->getRouteKey());
        $this->assertSame($site->token, $site->toArray()['id']);
        $this->assertArrayNotHasKey('token', $site->toArray());
        $this->assertIsInt($site->id);
        $this->assertSame($site->id, Site::idFromToken($site->token));
        $this->assertNull(Site::idFromToken('missing'));
    }

    public function test_app_routes_find_by_token_only(): void
    {
        $this->migrate();
        DB::table('users')->insert(['id' => 1, 'name' => 'owner']);
        $site = Site::create(['user_id' => 1, 'domain' => 'shop.ir']);
        $this->actingAs(User::find(1), 'sanctum');

        $this->getJson("/api/v1/sites/{$site->token}")->assertOk()->assertJsonPath('site.id', $site->token);
        $this->getJson("/api/v1/sites/{$site->id}")->assertNotFound();
    }
}
