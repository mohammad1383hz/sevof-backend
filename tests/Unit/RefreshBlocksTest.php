<?php

namespace Tests\Unit;

use App\Services\AI\ArticleRefreshService;
use App\Services\AI\ChatModelResolver;
use App\Support\RefreshBlocks;
use Tests\TestCase;

class RefreshBlocksTest extends TestCase
{
    public function test_untouched_text_rebuilds_byte_for_byte(): void
    {
        $text = "پاراگراف اول.\n\n### تیتر\nپاراگراف دوم با [لینک](LINK_1).\n\n\nپاراگراف آخر.\n";
        $parts = RefreshBlocks::split($text);

        $this->assertSame($text, RefreshBlocks::rebuild($parts, [], []));
        $this->assertSame(['پاراگراف اول.', "### تیتر\nپاراگراف دوم با [لینک](LINK_1).", "پاراگراف آخر.\n"], array_values(array_filter($parts, fn ($p, $i) => $i % 2 === 0, ARRAY_FILTER_USE_BOTH)));
    }

    public function test_replace_and_insert_touch_only_their_paragraph(): void
    {
        $parts = RefreshBlocks::split("اول.\n\nدوم.\n\nآخر.\n");

        $this->assertSame(
            "اول کامل‌تر.\n\nدوم.\n\nآخر.\n\nپاراگراف تازه.\n",
            RefreshBlocks::rebuild($parts, [0 => ' اول کامل‌تر. '], [4 => ['پاراگراف تازه.']]),
        );
    }

    public function test_markdown_edit_safety(): void
    {
        $original = 'گلدان سرامیکی سنگین است و [راهنما](LINK_1) را ببینید.';

        $this->assertSame([], RefreshBlocks::problems($original, 'گلدان سرامیکی سنگین است، پس سوراخ زهکشی مهم است و [راهنما](LINK_1) را ببینید.', RefreshBlocks::MARKDOWN));
        $this->assertContains('tokens_changed', RefreshBlocks::problems($original, 'گلدان سرامیکی سنگین است و راهنما را ببینید و بیشتر بخوانید.', RefreshBlocks::MARKDOWN));
        $this->assertContains('tokens_in_new_block', RefreshBlocks::problems(null, 'پاراگراف تازه با [لینک](LINK_1).', RefreshBlocks::MARKDOWN));
        $this->assertContains('forbidden_markup', RefreshBlocks::problems(null, "## تیتر اصلی تازه\nمتن", RefreshBlocks::MARKDOWN));
        $this->assertContains('too_short', RefreshBlocks::problems($original, 'کوتاه [راهنما](LINK_1)', RefreshBlocks::MARKDOWN));
    }

    public function test_html_edit_safety(): void
    {
        $original = "{{KEEP_1}}\n<p>متن با {{A_1}}لینک</a>.</p>\n{{KEEP_2}}";

        $this->assertSame([], RefreshBlocks::problems($original, "{{KEEP_1}}\n<p>متن کامل‌تر با {{A_1}}لینک مفید</a>.</p>\n{{KEEP_2}}", RefreshBlocks::HTML));
        $this->assertContains('tokens_changed', RefreshBlocks::problems($original, "{{KEEP_2}}\n<p>متن کامل‌تر با {{A_1}}لینک</a>.</p>\n{{KEEP_1}}", RefreshBlocks::HTML));
        $this->assertContains('forbidden_tag', RefreshBlocks::problems(null, '<p>تازه</p><script>x()</script>', RefreshBlocks::HTML));
        $this->assertContains('tokens_in_new_block', RefreshBlocks::problems(null, '<p>تازه {{A_1}}لینک</a></p>', RefreshBlocks::HTML));
        $this->assertFalse(RefreshBlocks::isEditable('{{KEEP_1}}', RefreshBlocks::HTML));
    }

    public function test_pro_refresh_of_a_typical_article_stays_cheap(): void
    {
        $service = $this->app->make(ArticleRefreshService::class);
        // مقاله‌ی ~۱۵۰۰ کلمه‌ای با مدل Pro؛ روش قبلی (۵ فراخوانی، بازنویسی کامل بخش‌ها) حدود ۸۵ کریدیت بود
        $estimate = $service->estimateForChars(ChatModelResolver::PRO_API_MODEL, 9000);

        $this->assertLessThanOrEqual(30, $estimate['max']);
        $this->assertLessThan($estimate['max'], $estimate['min'] + 1);
    }
}
