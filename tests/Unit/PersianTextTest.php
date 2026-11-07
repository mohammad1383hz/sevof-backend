<?php

namespace Tests\Unit;

use App\Support\PersianText;
use PHPUnit\Framework\TestCase;

class PersianTextTest extends TestCase
{
    public function test_fixes_half_spaces_letters_and_punctuation(): void
    {
        $this->assertSame(
            "این کتاب‌ها را نمی‌توان ساده خواند، اما می‌شود یاد گرفت؟",
            PersianText::normalize('این كتاب ها را نمی توان ساده خواند ،اما مي شود یاد گرفت ؟')
        );
    }

    public function test_finds_cliches_on_word_boundaries_only(): void
    {
        $found = PersianText::findPhrases('امروز هم بدون‌شک روز خوبی است و این محصول منحصر به فرد است.', PersianText::AI_CLICHES);

        $this->assertContains('منحصر به فرد', $found);
        $this->assertNotContains('امروزه', $found);
        $this->assertSame([], PersianText::findPhrases('امروزهمه‌چیز', ['امروزه']));
    }

    public function test_leaves_words_and_markdown_structure_alone(): void
    {
        $text = "### میز و صندلی\n\n- مورد اول\n  - مورد تودرتو\n\nهمایش [لینک](INTERNAL_LINK_URL) و میهن";

        $this->assertSame($text, PersianText::normalize($text));
    }

    public function test_formal_words_are_flagged_but_similar_plain_words_are_not(): void
    {
        $issues = \App\Support\PersianText::formalWordIssues('به منظور خرید بهتر، این نکته حائز اهمیت است و لذا باید دقت نمود.');
        $this->assertCount(3, $issues);
        $this->assertStringContainsString('به منظور', $issues[0]);

        // «لذت» و «منظورم» شبیه‌اند ولی کلمه‌ی رسمی نیستند
        $this->assertSame([], \App\Support\PersianText::formalWordIssues('از خرید لذت ببر؛ منظورم این است که با دقت انتخاب کنی.'));
    }

    public function test_latin_words_from_generated_article_are_flagged(): void
    {
        $text = 'اگر بررسی نشود، خطای factual ایجاد می‌کند؛ همان عواملی که با چارچوب E-E-A-T سنجیده می‌شوند. چک‌لیست QA و KPIها را ثبت کنید.';

        $this->assertSame(['factual', 'E-E-A-T', 'QA', 'KPI'], \App\Support\PersianText::latinWords($text));
    }

    public function test_link_targets_placeholders_and_allowed_brand_are_not_flagged(): void
    {
        $text = 'برای شروع [تولید مقاله](INTERNAL_LINK_URL) را ببینید یا به [منبع](https://example.com/AI-guide) سر بزنید؛ Youtix هم کمک می‌کند.';

        $this->assertSame([], \App\Support\PersianText::latinWords($text, ['Youtix']));
    }
}
