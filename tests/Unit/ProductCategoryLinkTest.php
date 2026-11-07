<?php

namespace Tests\Unit;

use App\Services\Products\PageProduct\PageProductGapDetectionService;
use App\Services\Products\PageProduct\ProductContentSanitizer as S;
use Tests\TestCase;

class ProductCategoryLinkTest extends TestCase
{
    private const URL = 'https://shop.test/product-category/%da%a9%d9%81%d8%b4/';

    public function test_first_mention_in_text_is_linked_and_rest_is_untouched(): void
    {
        $html = '<h2>کفش ورزشی</h2><p>این کفش ورزشی <img src="a.jpg"> سبک است.</p><p>کفش ورزشی دوم</p>';
        $out = S::withCategoryLink($html, 'کفش ورزشی', self::URL);

        // تیتر لینک نمی‌شود؛ اولین اشاره داخل پاراگراف لینک می‌شود و تصویر می‌ماند
        $this->assertSame(
            '<h2>کفش ورزشی</h2><p>این <a href="'.self::URL.'">کفش ورزشی</a> <img src="a.jpg"> سبک است.</p><p>کفش ورزشی دوم</p>',
            $out
        );
        $this->assertSame(1, substr_count($out, '<a '));
    }

    public function test_sentence_is_appended_when_category_name_is_absent(): void
    {
        $out = S::withCategoryLink('<p>متن محصول</p>', 'کفش ورزشی', self::URL);

        $this->assertStringStartsWith('<p>متن محصول</p>', $out);
        $this->assertStringContainsString('<a href="'.self::URL.'">کفش ورزشی</a>', $out);
        $this->assertSame('<p><a href="'.self::URL.'">Shoes</a></p>', S::withCategoryLink('', 'Shoes', self::URL, false));
    }

    public function test_existing_link_is_detected_in_any_form(): void
    {
        $this->assertTrue(S::hasLinkTo('<a href="http://www.shop.test/product-category/کفش">x</a>', self::URL));
        $this->assertTrue(S::hasLinkTo("<a class='c' href='/product-category/%DA%A9%D9%81%D8%B4/'>x</a>", self::URL));
        $this->assertFalse(S::hasLinkTo('<a href="https://shop.test/product-category/other/">x</a>', self::URL));

        $already = '<p><a href="'.self::URL.'">کفش ورزشی</a></p>';
        $this->assertSame($already, S::withCategoryLink($already, 'کفش ورزشی', self::URL));
    }

    public function test_link_inside_existing_anchor_is_not_nested(): void
    {
        $out = S::withCategoryLink('<p><a href="/x">کفش ورزشی</a> و کفش ورزشی</p>', 'کفش ورزشی', self::URL);

        $this->assertSame('<p><a href="/x">کفش ورزشی</a> و <a href="'.self::URL.'">کفش ورزشی</a></p>', $out);
    }

    public function test_link_only_fix_needs_no_ai_call(): void
    {
        $link = ['name' => 'کفش', 'url' => self::URL];
        $result = $this->app->make(\App\Services\Products\PageProduct\PageProductContentGeneratorService::class)
            ->generate(['category_link' => $link], ['category_link'], new \App\Models\User);

        $this->assertSame($link, $result['category_link']);
        $this->assertSame($link, $result['category']);
    }

    public function test_wp_link_only_keeps_seller_html_and_prepares_themed_fix(): void
    {
        $service = $this->app->make(\App\Services\Wp\PageProduct\PageProductSeoWpPublishService::class);
        $link = ['name' => 'کفش', 'url' => self::URL];
        $linked = fn (string $h) => S::withCategoryLink($h, 'کفش', self::URL);
        $product = (new \App\Models\SiteProduct)->forceFill([
            'description' => '<div class="x"><img src="a.jpg"><p>یک کفش راحت</p></div><!--sevof-faq--><h2>سوالات</h2><!--/sevof-faq-->',
        ]);

        $call = fn () => [$this->categoryLinkOnlyDescription($product, $link, true, $linked), $this->themedLinkFix];
        [$body, $fix] = \Closure::bind($call, $service, $service::class)();

        $this->assertSame('<div class="x"><img src="a.jpg"><p>یک <a href="'.self::URL.'">کفش</a> راحت</p></div>', $body);
        $this->assertStringContainsString('<a href="'.self::URL.'">کفش</a>', $fix);

        // بلوک سئوی قبلی (themed) وجود دارد: فقط همان بلوک فرستاده می‌شود و اصلاح بعدی لازم نیست
        $product->description = '<p>متن فروشنده</p><!--sevof-desc--><p>متن سئو</p><!--/sevof-desc-->';
        $call = fn () => [$this->categoryLinkOnlyDescription($product, $link, true, $linked), $this->themedLinkFix = null];
        [$body] = \Closure::bind($call, $service, $service::class)();
        $this->assertStringStartsWith('<p>متن سئو</p>', $body);
        $this->assertStringNotContainsString('متن فروشنده', $body);
    }

    public function test_missing_reports_category_link_only_when_description_is_kept(): void
    {
        $gaps = new PageProductGapDetectionService;
        $base = [
            'platform' => 'wordpress', 'short_description' => 'x', 'short_words' => 20,
            'description' => 'x', 'description_words' => 100, 'attributes' => [['name' => 'a', 'value' => 'b']],
            'tags' => ['t'], 'has_faq' => true, 'images' => [],
            'category_link' => ['name' => 'کفش', 'url' => self::URL], 'has_category_link' => false,
        ];

        $this->assertSame(['category_link'], $gaps->missing($base, true));
        $this->assertSame([], $gaps->missing(['has_category_link' => true] + $base, true));
        $this->assertSame([], $gaps->missing(['category_link' => null] + $base, true));
        // توضیحات ناقص: توضیحات تولید می‌شود و لینک دسته هم جزو ایرادها دیده می‌شود
        $this->assertSame(['description', 'category_link'], $gaps->missing(['description_words' => 3] + $base, true));
    }
}
