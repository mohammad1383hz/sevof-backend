<?php

namespace Tests\Unit;

use App\Services\Wp\PastPostsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** endpointهای اپ که با کلید API باز شده‌اند: سایتِ کلید جای {site} می‌نشیند و سایت دیگران در دسترس نیست. */
class PublicApiAppRoutesTest extends TestCase
{
    private const KEY = 'sk_agent_test_key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('user_id');
            $t->string('domain')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('api_agents', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('site_id')->nullable();
            $t->string('name');
            $t->string('key_hash', 64);
            $t->string('key_last_four', 4);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('page_product_seo_optimizations', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable()->unique();
            $t->unsignedBigInteger('site_id');
            $t->timestamps();
        });

        DB::table('users')->insert([['id' => 1, 'name' => 'owner'], ['id' => 2, 'name' => 'other']]);
        DB::table('sites')->insert([['id' => 10, 'user_id' => 1, 'domain' => 'mine.ir'], ['id' => 20, 'user_id' => 2, 'domain' => 'other.ir']]);
        DB::table('api_agents')->insert(['user_id' => 1, 'site_id' => 10, 'name' => 'k', 'key_hash' => hash('sha256', self::KEY), 'key_last_four' => 'tkey']);
        DB::table('page_product_seo_optimizations')->insert(['id' => 5, 'token' => '01m6e5bpntvz4cz9dr9yr6d87k', 'site_id' => 20]);
    }

    public function test_app_endpoint_runs_for_the_key_site_and_owner(): void
    {
        $this->mock(PastPostsService::class, fn ($m) => $m->shouldReceive('connection')->andReturn(null));

        // اگر سایت یا کاربر درست جا نیفتاده بود، کنترلر 403 یا خطای نوع می‌داد؛ این پیام یعنی تا داخل منطق اپ رسیده
        $this->postJson('/api/agent/v1/past-articles/sync', [], ['X-Agent-Key' => self::KEY])
            ->assertStatus(422)
            ->assertJson(['message' => 'Connect your WordPress site to Sevof first.']);
    }

    public function test_items_of_another_site_are_not_reachable(): void
    {
        // با توکن و با آی‌دی عددی قدیمی
        $this->getJson('/api/agent/v1/product-fixes/5', ['X-Agent-Key' => self::KEY])->assertNotFound();
        $this->getJson('/api/agent/v1/product-fixes/01m6e5bpntvz4cz9dr9yr6d87k', ['X-Agent-Key' => self::KEY])->assertNotFound();
    }

    public function test_key_is_required(): void
    {
        $this->getJson('/api/agent/v1/product-articles')->assertUnauthorized();
    }
}
