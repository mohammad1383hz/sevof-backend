<?php

namespace Tests\Unit;

use App\Services\Crawler\SiteCrawlerService;
use App\Services\SiteLanguageDetector;
use Tests\TestCase;

class SiteLanguageDetectionTest extends TestCase
{
    /** متن واقعی iranbato: فارسی + نام محصولات لاتین + کد JS نشت‌کرده (قبلاً en با ۲۳٪ تشخیص داده می‌شد). */
    private const PERSIAN_SHOP_WITH_LEAKED_JS = "ایران باتو | بهترین قیمت +ضمانت اصالت کالا. کنسول بازی PS4 Pro حافظه 1 ترابایت. "
        ."گوشی آیفون Apple iPhone 15 Pro Max Samsung Galaxy S24 Ultra 256GB Xbox Series S. "
        ."'; } } updateTimer(); countdownInterval = setInterval(updateTimer, 1000); } window.bakalaCountdown = bakalaCountdown; "
        ."var swiper_options = {slidesPerView: 4, spaceBetween: 10}; document.querySelector('.product_list').addEventListener('click', handleClick);";

    public function test_persian_shop_with_latin_products_and_leaked_code_is_persian_with_high_confidence(): void
    {
        $result = (new SiteLanguageDetector)->detect([['title' => '', 'content' => self::PERSIAN_SHOP_WITH_LEAKED_JS]]);

        $this->assertSame('fa', $result['code']);
        $this->assertGreaterThanOrEqual(70, $result['confidence']);
    }

    public function test_english_site_with_a_few_persian_words_stays_english(): void
    {
        $text = str_repeat('We sell handmade carpets and rugs shipped worldwide with free returns and a two year warranty. ', 6)
            .'فرش دستباف';

        $this->assertSame('en', (new SiteLanguageDetector)->detect([['title' => 'Handmade rugs', 'content' => $text]])['code']);
    }

    public function test_crawler_drops_script_code_and_keeps_declared_language(): void
    {
        $html = '<html lang="fa-IR"><head><title>فروشگاه</title><script>var a = "</span>"; updateTimer(); countdownInterval = 1;</script>'
            .'<style>.x{color:red}</style></head><body><main><p>خرید کفش ورزشی اصل</p></main></body></html>';

        $page = (fn () => $this->extractPageData('https://shop.test/', $html, 'shop.test'))
            ->call($this->app->make(SiteCrawlerService::class))['content'];

        $this->assertSame('خرید کفش ورزشی اصل', $page['content']);
        $this->assertSame('fa-IR', $page['html_lang']);
    }
}
