<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Support\ContentLanguage;
use Tests\TestCase;

class ContentLanguageTest extends TestCase
{
    public function test_persian_prompt_is_unchanged(): void
    {
        $prompt = 'همه خروجی فارسی باشد. Write fluent Persian.';

        $this->assertSame($prompt, ContentLanguage::localize($prompt, 'fa'));
    }

    public function test_english_site_rewrites_language_and_appends_directive(): void
    {
        $out = ContentLanguage::localize('همه خروجی فارسی باشد. Write fluent Persian.', 'en');

        $this->assertStringNotContainsString('فارسی باشد', $out);
        $this->assertStringContainsString('همه خروجی انگلیسی باشد', $out);
        $this->assertStringContainsString('Write fluent English.', $out);
        $this->assertStringContainsString('OUTPUT LANGUAGE', $out);
    }

    public function test_site_language_comes_from_column_or_crawled_pages(): void
    {
        // forceFill: fill() ستون‌هایی را که در DB تست نیستند بی‌صدا حذف می‌کند.
        $this->assertSame('de', ContentLanguage::forSite((new Site)->forceFill(['language' => 'de-DE'])));

        // سایت قدیمی بدون ستون language: از محتوای کراول‌شده تشخیص داده می‌شود (بدون ذخیره چون exists=false)
        $site = (new Site)->forceFill([
            'crawled_pages' => [[
                'title' => 'Best running shoes for beginners',
                'content' => 'We help you choose the right shoes for your feet and your budget. Our team tests every pair and we share what we learn with you.',
            ]],
        ]);
        $this->assertSame('en', ContentLanguage::forSite($site));

        $this->assertSame('en', ContentLanguage::forSite(null));
    }

    public function test_weak_detection_never_switches_a_site_off_persian(): void
    {
        // زبان ذخیره‌شده با اطمینان پایین (باگ iranbato: en با ۱۴٪) → فارسی؛ با اطمینان بالا همان زبان
        $this->assertSame('en', ContentLanguage::forSite((new Site)->forceFill(['language' => 'de', 'language_confidence' => 14])));
        $this->assertSame('en', ContentLanguage::forSite((new Site)->forceFill(['language' => 'en', 'language_confidence' => 90])));

        $detector = new \App\Services\SiteLanguageDetector;
        $mixed = [['title' => 'iPhone 15 Pro Max', 'content' => 'خرید Samsung Galaxy S24 Ultra 256GB Apple Watch']];

        // متن لاتین کم/مبهم بدون lang اعلام‌شده: تصمیمی گرفته نمی‌شود (یعنی پیش‌فرض فارسی)
        $this->assertNull($detector->detect($mixed)['code']);

        // همان متن ولی سایت خودش fa-IR اعلام کرده → فارسی
        $this->assertSame('fa', $detector->detect($mixed, '', 'fa-IR')['code']);
    }
}
