<?php

namespace Tests\Unit;

use App\Services\Crawler\SiteCrawlerService;
use Tests\TestCase;

class SiteCrawlerServiceTest extends TestCase
{
    public function test_normalizes_domain(): void
    {
        $service = app(SiteCrawlerService::class);

        $normalized = $service->normalizeDomain('www.Example.com/about');

        $this->assertSame('example.com', $normalized['domain']);
        $this->assertSame('https://example.com', $normalized['url']);
    }
}
