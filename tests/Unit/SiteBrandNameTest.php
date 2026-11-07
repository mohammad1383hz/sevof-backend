<?php

namespace Tests\Unit;

use App\Models\Site;
use Tests\TestCase;

class SiteBrandNameTest extends TestCase
{
    private function brand(array $titles, string $domain = 'shop.test'): string
    {
        return (new Site)->forceFill([
            'domain' => $domain,
            'crawled_pages' => array_map(fn ($t) => ['title' => $t], $titles),
        ])->brandName();
    }

    public function test_brand_name_comes_from_repeated_title_part(): void
    {
        $this->assertSame('ایران باتو', $this->brand(['ایران باتو | بهترین قیمت +ضمانت اصالت کالا +7 روز بازگشت کالا', 'درباره ایران باتو - ایران باتو']));
        $this->assertSame('سافت سئو', $this->brand(['آموزش سئو و خدمات سئو - سافت سئو', 'درباره ما - سافت سئو']));
        $this->assertSame('گالری عطر مهان', $this->brand(['گالری عطر مهان – Mahan Perfume Gallery', 'درباره ما – گالری عطر مهان']));
    }

    public function test_falls_back_to_domain_when_title_has_no_usable_name(): void
    {
        $this->assertSame('yb459350.mixin.website', $this->brand(['yb459350'], 'yb459350.mixin.website'));
        $this->assertSame('shop.test', $this->brand([]));
    }
}
