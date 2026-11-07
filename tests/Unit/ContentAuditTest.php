<?php

namespace Tests\Unit;

use App\Support\ContentAudit;
use App\Support\RefreshBlocks;
use App\Support\RefreshLinks;
use Carbon\Carbon;
use Tests\TestCase;

/** بررسی سئوی مقاله بدون AI: لینک داخلی، سال‌های قدیمی، تازگی؛ و لینک‌های تازه‌ی به‌روزرسانی. */
class ContentAuditTest extends TestCase
{
    public function test_internal_links_are_counted_once_and_files_or_other_sites_are_not(): void
    {
        $html = '<p><a href="https://www.shop.ir/a">الف</a> <a href="/b#x">ب</a> <a href="https://shop.ir/a">تکرار</a>'
            .' <a href="https://other.ir/c">بیرونی</a> <a href="https://shop.ir/wp-content/uploads/x.jpg">عکس</a> <a href="#top">بالا</a></p>';

        $links = ContentAudit::links($html, RefreshBlocks::HTML, 'shop.ir');

        $this->assertSame(['https://www.shop.ir/a', '/b'], $links['internal']);
        $this->assertSame(1, $links['external']);

        $md = ContentAudit::links('[الف](https://shop.ir/a) و [ب](https://other.ir/b)', RefreshBlocks::MARKDOWN, 'shop.ir');
        $this->assertSame(['https://shop.ir/a'], $md['internal']);
    }

    public function test_outdated_years_in_both_calendars_and_persian_digits(): void
    {
        $now = Carbon::parse('2026-11-02');

        $this->assertSame([2024, 1403], ContentAudit::outdatedYears('بهترین گوشی‌های ۱۴۰۳ و سال 2024، تا ۱۴۰۵ و 2026، کد 13450', $now));
        $this->assertSame(1405, ContentAudit::today($now)['jalali_year']);
    }

    public function test_issues_drive_needs_update(): void
    {
        $now = Carbon::parse('2026-11-02');
        $audit = ['words' => 1600, 'internal_links' => 0, 'headings' => 4, 'outdated_years' => [1402]];

        $issues = ContentAudit::issues($audit, 'راهنمای خرید لپ‌تاپ ۱۴۰۲', $now->copy()->subMonths(20), $now);
        $codes = array_column($issues, 'code');

        $this->assertSame(['outdated_year_title', 'no_internal_links', 'stale'], $codes);
        $this->assertTrue(ContentAudit::needsUpdate($issues));

        // متن خوب و تازه: ایرادی نیست
        $good = ContentAudit::issues(['words' => 1200, 'internal_links' => 4, 'headings' => 5, 'outdated_years' => []], 'راهنمای خرید لپ‌تاپ', $now->copy()->subMonth(), $now);
        $this->assertSame([], $good);
        $this->assertFalse(ContentAudit::needsUpdate($good));

        // یک ایراد متوسط به‌تنهایی «آپدیت لازم» نیست
        $weak = ContentAudit::issues(['words' => 1600, 'internal_links' => 2, 'headings' => 5, 'outdated_years' => []], 'راهنما', null, $now);
        $this->assertSame(['weak_internal_links'], array_column($weak, 'code'));
        $this->assertFalse(ContentAudit::needsUpdate($weak));
    }

    public function test_new_link_markers_are_validated_and_rendered(): void
    {
        $candidates = [1 => ['url' => 'https://shop.ir/soil', 'title' => 'خاک'], 2 => ['url' => 'https://shop.ir/pot', 'title' => 'گلدان']];
        $html = '<h3>[[L2|تیتر گلدان سرامیکی]]</h3><p>برای [[L1|انتخاب خاک مناسب]] و [[L1|دوباره خاک مناسب]] و [[L2|اینجا را ببینید]] و [[L9|صفحه‌ی ناشناخته]] و [[L2|گلدان سرامیکی بزرگ]].</p>';

        [$clean, $used] = RefreshLinks::sanitize($html, RefreshBlocks::HTML, $candidates, []);

        $this->assertSame([1, 2], $used);
        $this->assertSame([1 => 'انتخاب خاک مناسب', 2 => 'گلدان سرامیکی بزرگ'], RefreshLinks::markers($clean));
        $this->assertStringContainsString('<h3>تیتر گلدان سرامیکی</h3>', $clean);

        $rendered = RefreshLinks::render($clean, RefreshBlocks::HTML, $candidates);
        $this->assertStringContainsString('<a href="https://shop.ir/soil">انتخاب خاک مناسب</a>', $rendered);
        $this->assertStringNotContainsString('[[', $rendered);

        // صفحه‌ای که تغییر قبلی مصرف کرده دوباره لینک نمی‌شود
        [, $usedAgain] = RefreshLinks::sanitize('[[L1|انتخاب خاک مناسب]]', RefreshBlocks::MARKDOWN, $candidates, [1]);
        $this->assertSame([], $usedAgain);
        $this->assertSame('[خاک خوب گلدان](https://shop.ir/soil)', RefreshLinks::render('[[L1|خاک خوب گلدان]]', RefreshBlocks::MARKDOWN, $candidates));
    }
}
