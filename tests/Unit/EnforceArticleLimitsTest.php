<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnforceArticleLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.foreign_key_constraints' => false]);
        DB::purge('sqlite');

        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('token', 26)->nullable();
            $t->string('slug');
            $t->integer('article_limit')->default(5);
            $t->boolean('is_free')->default(false);
            $t->decimal('credit', 12, 4)->default(0);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('plan_id');
            $t->unsignedTinyInteger('period_months')->default(1);
            $t->decimal('credit_limit', 12, 4)->nullable();
            $t->decimal('credit_used', 12, 4)->default(0);
            $t->unsignedBigInteger('tokens_used')->default(0);
            $t->string('status')->default('active');
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('sites', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->softDeletes();
        });
        Schema::create('agent_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('next_articles_run_at')->nullable();
            $t->timestamps();
        });
        Schema::create('articles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('website_id');
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        Schema::create('product_articles', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->unsignedBigInteger('site_id');
            $t->string('status')->default('pending');
            $t->timestamps();
        });

        // pro: 7 articles/month, pro plus: 20 articles/month
        DB::table('plans')->insert([
            ['id' => 1, 'slug' => 'pro', 'article_limit' => 7, 'credit' => 530],
            ['id' => 2, 'slug' => 'pro-plus', 'article_limit' => 20, 'credit' => 1160],
        ]);
    }

    /** A user with one site (Agent on) and an active subscription that started 10 days ago */
    private function user(int $id, int $planId, int $months, int $articles, string $articleStatus = 'completed'): int
    {
        DB::table('users')->insert(['id' => $id]);
        DB::table('sites')->insert(['id' => $id, 'user_id' => $id]);
        DB::table('agent_settings')->insert(['site_id' => $id, 'agent_enabled' => true, 'next_articles_run_at' => now()->addDay()]);

        // an article from before the subscription started must not count
        DB::table('articles')->insert(['website_id' => $id, 'status' => 'completed', 'created_at' => now()->subDays(40)]);
        for ($i = 0; $i < $articles; $i++) {
            DB::table('articles')->insert(['website_id' => $id, 'status' => $articleStatus, 'created_at' => now()->subDays(5)]);
        }

        return DB::table('subscriptions')->insertGetId([
            'user_id' => $id, 'plan_id' => $planId, 'period_months' => $months, 'status' => 'active',
            'starts_at' => now()->subDays(10), 'ends_at' => now()->addDays(30 * $months - 10), 'created_at' => now()->subDays(10),
        ]);
    }

    private function subStatus(int $subscriptionId): string
    {
        return DB::table('subscriptions')->where('id', $subscriptionId)->value('status');
    }

    private function agentEnabled(int $siteId): bool
    {
        return (bool) DB::table('agent_settings')->where('site_id', $siteId)->value('agent_enabled');
    }

    public function test_limits_follow_plan_and_period(): void
    {
        $monthlyFull = $this->user(1, 1, 1, 7);        // 7/7 → expire
        $monthlyUnder = $this->user(2, 1, 1, 6);       // 6/7 → stays
        $quarterlyUnder = $this->user(3, 2, 3, 59);    // 59/60 → stays
        $quarterlyFull = $this->user(4, 2, 3, 61);     // 61/60 → expire
        $failedOnly = $this->user(5, 1, 1, 9, 'failed'); // failed ones don't count

        // 4 articles + 3 product articles = 7/7 → expire (a failed product article doesn't count)
        $mixed = $this->user(6, 1, 1, 4);
        foreach (['completed', 'pending', 'paused_credit', 'failed'] as $i => $status) {
            DB::table('product_articles')->insert(['id' => "p{$i}", 'site_id' => 6, 'status' => $status, 'created_at' => now()->subDays(2)]);
        }

        $this->artisan('subscriptions:enforce-article-limits')->assertSuccessful();

        $this->assertSame('expired', $this->subStatus($mixed));
        $this->assertFalse($this->agentEnabled(6));
        $this->assertSame('expired', $this->subStatus($monthlyFull));
        $this->assertFalse($this->agentEnabled(1));
        $this->assertSame('active', $this->subStatus($monthlyUnder));
        $this->assertTrue($this->agentEnabled(2));
        $this->assertSame('active', $this->subStatus($quarterlyUnder));
        $this->assertSame('expired', $this->subStatus($quarterlyFull));
        $this->assertFalse($this->agentEnabled(4));
        $this->assertSame('active', $this->subStatus($failedOnly));
    }

    public function test_queued_plan_takes_over_and_agent_stays_on(): void
    {
        $current = $this->user(1, 1, 1, 7);
        $queued = DB::table('subscriptions')->insertGetId([
            'user_id' => 1, 'plan_id' => 2, 'period_months' => 6, 'status' => 'queued', 'created_at' => now(),
        ]);

        $this->artisan('subscriptions:enforce-article-limits')->assertSuccessful();

        $this->assertSame('expired', $this->subStatus($current));
        $this->assertSame('active', $this->subStatus($queued));
        $this->assertNotNull(DB::table('subscriptions')->where('id', $queued)->value('starts_at'));
        $this->assertTrue($this->agentEnabled(1));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $sub = $this->user(1, 1, 1, 8);

        $this->artisan('subscriptions:enforce-article-limits --dry-run')->assertSuccessful();

        $this->assertSame('active', $this->subStatus($sub));
        $this->assertTrue($this->agentEnabled(1));
    }
}
