<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\SiteController;
use App\Http\Requests\Site\StoreSiteRequest;
use App\Models\User;
use App\Services\Crawler\SiteCrawlerService;
use App\Services\SubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SiteDomainLimitTest extends TestCase
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
            $t->string('domain');
            $t->string('url')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Bus::fake();
    }

    private function register(int $userId, bool $paid = false): int
    {
        $crawler = Mockery::mock(SiteCrawlerService::class);
        $crawler->shouldReceive('normalizeDomain')->andReturn(['domain' => 'shop.ir', 'url' => 'https://shop.ir']);
        $subscriptions = Mockery::mock(SubscriptionService::class);
        $subscriptions->shouldReceive('hasActivePaidPlan')->andReturn($paid);

        $request = StoreSiteRequest::create('/api/sites', 'POST', ['domain' => 'shop.ir']);
        $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => $userId]));

        return app(SiteController::class)->store($request, $crawler, $subscriptions)->status();
    }

    public function test_free_users_can_register_a_domain_at_most_twice(): void
    {
        $this->assertSame(202, $this->register(1));
        $this->assertSame(202, $this->register(2));
        $this->assertSame(409, $this->register(3));
        $this->assertSame(202, $this->register(4, paid: true));

        DB::table('sites')->where('user_id', 2)->update(['deleted_at' => now()]);
        $this->assertSame(409, $this->register(3)); // ثبت کاربر پولی هم شمرده می‌شود
    }
}
