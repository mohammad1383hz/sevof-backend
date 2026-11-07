<?php

namespace Tests\Unit;

use App\Support\PersianWritingSkill;
use Tests\TestCase;

class PersianWritingSkillTest extends TestCase
{
    /** پاراگراف ماشینیِ واقعی که سیستم نوشته بود */
    private const MACHINE_TEXT = 'تولید محتوا با هوش مصنوعی زمانی اهمیت پیدا می‌کند که فروشگاه شما به توضیح محصول، مقاله و محتوای تبلیغاتی بیشتری نیاز دارد، اما تیم محتوا فرصت کافی ندارد. در ادامه، یاد می‌گیرید چگونه برای صفحات محصول و مطالب وبلاگ، ورودی دقیق بدهید، خروجی را از نظر واقعیت و لحن بررسی کنید و متن را برای جست‌وجو و خواندن انسان‌ها آماده سازید. هدف، جایگزین‌کردن قضاوت نویسنده نیست؛ بلکه افزایش سرعت، مقیاس‌پذیری و کیفیت تصمیم‌گیری در فرایند تولید محتواست.';

    public function test_machine_paragraph_is_flagged_for_long_sentences_and_heavy_words(): void
    {
        $this->assertCount(2, PersianWritingSkill::longSentences(self::MACHINE_TEXT));
        $this->assertTrue(PersianWritingSkill::lineHasIssue(self::MACHINE_TEXT));
    }

    public function test_simple_rewrite_passes(): void
    {
        $simple = 'فروشگاه شما هر هفته چند محصول تازه دارد. هر کدام توضیح می‌خواهد و چند خط هم برای شبکه‌های اجتماعی. اما وقت نوشتن همه‌ی این‌ها را ندارید. اینجاست که هوش مصنوعی به کارتان می‌آید.';

        $this->assertSame([], PersianWritingSkill::issues($simple));
    }

    public function test_links_and_list_markers_do_not_count_as_words(): void
    {
        $item = '- **نور کم:** یک ساعت قبل از خواب چراغ‌ها را خاموش کنید و [راهنمای خواب کودک](RELATED_LINK_1) را بخوانید.';

        $this->assertSame([], PersianWritingSkill::longSentences($item));
    }

    public function test_skill_file_is_loaded_with_word_list(): void
    {
        $prompt = PersianWritingSkill::prompt();

        $this->assertStringContainsString('هر جمله به جمله‌ی قبل وصل باشد', $prompt);
        $this->assertStringContainsString('«به منظور» ← «برای»', $prompt);
        $this->assertStringNotContainsString('{WORD_LIST}', $prompt);
    }

    public function test_section_writer_prompt_keeps_every_rule_but_is_shorter(): void
    {
        $full = PersianWritingSkill::prompt();
        $writer = PersianWritingSkill::writerPrompt();

        foreach (['## ۱.', '## ۲.', '## ۳.', '## ۴.', '## ۵.', '## ۶.', '## ۷.', '## ۸.', '## ۹.'] as $heading) {
            $this->assertStringContainsString($heading, $writer);
        }
        $this->assertSame(PersianWritingSkill::WRITER_EXAMPLES, substr_count($writer, '✓'));
        $this->assertStringContainsString('«نه این، بلکه آن»', $writer);
        $this->assertStringContainsString('«به منظور» ← «برای»', $writer);
        $this->assertStringNotContainsString('«مبرهن»', $writer); // فهرست کامل فقط در prompt() و چک بعد از نوشتن
        $this->assertStringNotContainsString('{WORD_LIST}', $writer);
        $this->assertLessThan(mb_strlen($full) * 0.7, mb_strlen($writer));
    }

    public function test_headings_lose_latin_without_touching_other_words(): void
    {
        $this->assertSame('ویرایش انسانی پس از تولید با هوش مصنوعی', PersianWritingSkill::fixHeading('ویرایش انسانی پس از تولید با AI'));
        $this->assertSame('لحن نامناسب با مخاطب', PersianWritingSkill::fixHeading('تنالیته نامناسب با مخاطب'));
        // «لید» داخل «تولید» نباید عوض شود
        $this->assertSame('اشتباهات تولید محتوا', PersianWritingSkill::fixHeading('اشتباهات تولید محتوا'));
    }

    public function test_general_jargon_rule_and_word_list_work_together(): void
    {
        // فهرست کلمه‌های رایج خودکار چک می‌شود؛ قانون کلی برای بقیه‌ی اصطلاح‌های هر حوزه در promptهاست
        $this->assertCount(2, PersianWritingSkill::wordIssues('اول پرسونا را بسازید و تنالیته را درست کنید؛ کلید کار همین است.'));
        $this->assertStringContainsString('هیچ اصطلاح تخصصیِ هیچ حوزه‌ای', PersianWritingSkill::prompt());
        $this->assertStringContainsString('هیچ اصطلاح تخصصیِ هیچ حوزه‌ای', PersianWritingSkill::compact());
    }

    public function test_double_list_numbers_are_removed(): void
    {
        $text = "1. ۱.لید: مشکل را بگویید.\n2. ۲. مسئله: دلیلش را بگویید.\n3. راه‌حل: مراحل را بنویسید.";

        $this->assertSame(
            "1. لید: مشکل را بگویید.\n2. مسئله: دلیلش را بگویید.\n3. راه‌حل: مراحل را بنویسید.",
            \App\Support\PersianText::normalize($text)
        );
    }

    public function test_brand_mention_always_goes_to_conclusion(): void
    {
        $service = $this->app->make(\App\Services\AI\ArticleWriterService::class);
        $sections = [
            ['type' => 'introduction', 'insert_brand_mention' => false],
            ['type' => 'section', 'insert_brand_mention' => true],
            ['type' => 'faq', 'insert_brand_mention' => false],
            ['type' => 'conclusion', 'insert_brand_mention' => false],
        ];

        $result = (fn () => $this->enforceSingleBrandMentionFlag($sections))->call($service);

        $this->assertSame([false, false, false, true], array_column($result, 'insert_brand_mention'));
    }

    public function test_product_headings_keep_model_names_but_drop_quotes_and_loanwords(): void
    {
        $service = $this->app->make(\App\Services\Products\ProductArticleWriterService::class);
        $result = (function () {
            $this->language = 'fa';

            return $this->cleanHeadings([
                ['type' => 'product', 'heading' => 'دستیار Google AI Pro؛ تنالیته آرام'],
                ['type' => 'guide', 'heading' => 'انتخاب «اسپیکر» برای AI', 'subheadings' => ['پرسونا کیست؟']],
            ]);
        })->call($service);

        $this->assertSame('دستیار Google AI Pro؛ لحن آرام', $result[0]['heading']);
        $this->assertSame('تنالیته‌ی آرام', \App\Support\PersianWritingSkill::fixHeading('تنالیته‌ی آرام'));
        $this->assertSame('انتخاب اسپیکر برای هوش مصنوعی', $result[1]['heading']);
        $this->assertSame(['خواننده‌ی هدف کیست؟'], $result[1]['subheadings']);
    }

    public function test_product_article_allows_only_product_latin_words(): void
    {
        $service = $this->app->make(\App\Services\Products\ProductArticleWriterService::class);
        $article = (new \App\Models\ProductArticle)->setRelation('site', null);
        $products = collect([(new \App\Models\SiteProduct)->forceFill(['name' => 'هدست گیمینگ JBL Quantum 400'])]);

        $issues = (function () use ($article, $products) {
            $this->language = 'fa';

            return $this->findLatinWords('هدست JBL Quantum 400 با Wi-Fi کار نمی‌کند.', $this->allowedLatin($article, $products));
        })->call($service);

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('Wi-Fi', $issues[0]);
    }
}
