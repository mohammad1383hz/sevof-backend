<?php

namespace Tests\Feature;

use App\Models\User;
use App\Jobs\AnalyzeSiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SeobotApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_submit_domain_for_analysis(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'phone' => '09121111111',
            'phone_verified_at' => now(),
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/sites', [
            'domain' => 'example.com',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('site.domain', 'example.com')
            ->assertJsonPath('site.status', 'pending');

        Bus::assertDispatched(AnalyzeSiteJob::class);
    }

    public function test_site_crawler_normalizes_domain(): void
    {
        $service = app(\App\Services\Crawler\SiteCrawlerService::class);

        $normalized = $service->normalizeDomain('www.Example.com/about');

        $this->assertSame('example.com', $normalized['domain']);
        $this->assertSame('https://example.com', $normalized['url']);
    }
}
