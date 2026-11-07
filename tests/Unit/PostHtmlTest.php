<?php

namespace Tests\Unit;

use App\Support\PostHtml;
use Tests\TestCase;

class PostHtmlTest extends TestCase
{
    private const GUTENBERG = "<!-- wp:paragraph -->\n<p>مقدمه‌ی کوتاه درباره‌ی <a href=\"https://shop.ir/pot\">گلدان</a>.</p>\n<!-- /wp:paragraph -->\n\n"
        ."<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">جنس گلدان</h2>\n<!-- /wp:heading -->\n\n"
        ."<!-- wp:image {\"id\":5} -->\n<figure class=\"wp-block-image\"><img src=\"https://shop.ir/a.jpg\" alt=\"\"/></figure>\n<!-- /wp:image -->\n\n"
        ."<!-- wp:paragraph -->\n<p>گلدان سرامیکی سنگین است.</p>\n<!-- /wp:paragraph -->\n\n"
        ."<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">سوالات متداول</h2>\n<!-- /wp:heading -->\n\n"
        ."[contact-form-7 id=\"12\"]\n<p>جواب کوتاه.</p>\n";

    public function test_split_is_lossless_and_keeps_block_markers_with_their_heading(): void
    {
        $sections = PostHtml::split(self::GUTENBERG);

        $this->assertSame(self::GUTENBERG, PostHtml::join(array_column($sections, 'html')));
        $this->assertSame(['introduction', 'section', 'faq'], array_column($sections, 'type'));
        $this->assertSame(['مقدمه', 'جنس گلدان', 'سوالات متداول'], array_column($sections, 'heading'));
        $this->assertStringStartsWith('<!-- wp:heading -->', $sections[1]['html']);
    }

    public function test_classic_post_without_h2_splits_on_h3(): void
    {
        $html = "متن اول\n\n<h3>بخش دوم</h3>\nمتن دوم";
        $sections = PostHtml::split($html);

        $this->assertCount(2, $sections);
        $this->assertSame('بخش دوم', $sections[1]['heading']);
        $this->assertSame($html, PostHtml::join(array_column($sections, 'html')));
    }

    public function test_mask_and_restore_round_trip_exactly(): void
    {
        foreach (PostHtml::split(self::GUTENBERG) as $section) {
            [$masked, $map] = PostHtml::mask($section['html']);

            $this->assertStringNotContainsString('<img', $masked);
            $this->assertStringNotContainsString('href=', $masked);
            $this->assertStringNotContainsString('<!--', $masked);
            $this->assertStringNotContainsString('[contact-form-7', $masked);
            $this->assertSame($section['html'], PostHtml::restore($masked, $map));
        }
    }

    public function test_safe_rewrite_passes_and_unsafe_rewrites_are_caught(): void
    {
        [$masked] = PostHtml::mask(PostHtml::split(self::GUTENBERG)[1]['html']);
        // {{KEEP_..}} ها: نشانه‌ی ویرایشگر، تیتر H2، تصویر؛ متن عوض می‌شود
        $good = (string) preg_replace('/گلدان سرامیکی سنگین است\./u', 'گلدان سرامیکی سنگین است. <strong>سوراخ زهکشی</strong> هم داشته باشد.</p>'."\n<p>این یک پاراگراف تازه است.", $masked);

        $this->assertSame([], PostHtml::problems($masked, $good));

        preg_match_all('/\{\{KEEP_\d+\}\}/', $masked, $tokens);
        $this->assertContains('protected_blocks_changed', PostHtml::problems($masked, str_replace($tokens[0][2], '', $good)));
        $this->assertContains('protected_blocks_changed', PostHtml::problems($masked, strtr($good, [$tokens[0][1] => $tokens[0][2], $tokens[0][2] => $tokens[0][1]])));
        $this->assertContains('forbidden_tag', PostHtml::problems($masked, $good.'<script>alert(1)</script>'));
        $this->assertContains('forbidden_attribute', PostHtml::problems($masked, str_replace('<strong>', '<strong onclick="x()">', $good)));
        $this->assertContains('unbalanced_p', PostHtml::problems($masked, $good.'<p>بدون بستن'));

        [$intro] = PostHtml::mask(PostHtml::split(self::GUTENBERG)[0]['html']);
        $this->assertContains('links_changed', PostHtml::problems($intro, (string) preg_replace('/\{\{A_1\}\}(.*?)<\/a>/u', '$1', $intro)));
    }
}
