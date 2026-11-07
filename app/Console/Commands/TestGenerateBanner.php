<?php
// app/Console/Commands/TestGenerateBanner.php

namespace App\Console\Commands;

use App\Jobs\GenerateArticleBannerJob;
use App\Models\Article;
use App\Models\Banner;
use Illuminate\Console\Command;
use Throwable;

/**
 * دستورِ تستیِ سریع: GenerateArticleBannerJob رو به‌صورتِ sync (نه از طریقِ
 * صف) اجرا می‌کنه تا فوری نتیجه رو ببینید — بدون نیاز به روشن بودنِ
 * queue worker. خروجیِ HTML رو هم توی یه فایل ذخیره می‌کنه تا مستقیم تو
 * مرورگر بازش کنید و ببینید بنر چه شکلیه.
 *
 * استفاده:
 *   php artisan banner:test 123
 *   php artisan banner:test 123 --force   (حتی اگه بنرِ generated داشته باشه، دوباره می‌سازه)
 */
class TestGenerateBanner extends Command
{
    protected $signature = 'banner:test {article_id : آیدیِ مقاله} {--force : حتی اگه بنرِ generated موجود باشه دوباره بساز}';

    protected $description = 'اجرای sync جاب تولید بنر برای یک مقاله، برای تست سریع بدون صف';

    public function handle(): int
    {
        $article = Article::find($this->argument('article_id'));

        if (! $article) {
            $this->error('مقاله‌ای با این id پیدا نشد.');
            return self::FAILURE;
        }

        if (! $article->site) {
            $this->error('این مقاله به هیچ سایتی متصل نیست.');
            return self::FAILURE;
        }

        $this->info("در حال تولید بنر برای مقاله #{$article->id} — سایت: {$article->site->domain}");
        $this->line('(اجرای sync؛ ممکنه چند ثانیه طول بکشه چون منتظرِ جوابِ AI و دانلودِ لوگو می‌مونیم...)');

        $startedAt = microtime(true);

        try {
            GenerateArticleBannerJob::dispatchSync($article, (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error('اجرای جاب با خطا متوقف شد: '.$e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $this->line("مدتِ اجرا: {$durationMs}ms");

        $article->refresh();
        $banner = $article->banners()->latest()->first();

        $this->newLine();
        $this->info('وضعیتِ banner_status روی مقاله: '.$article->banner_status);

        if (! $banner) {
            $this->error('هیچ بنری به این مقاله وصل نشد. احتمالاً به خاطرِ نبودِ اعتبار (credit) یا خطای دیگه، جاب زودتر متوقف شده.');
            return self::FAILURE;
        }

        $this->info('وضعیتِ خودِ رکوردِ بنر: '.$banner->status);
        $this->info('لوگوی استفاده‌شده: '.($banner->logo_url ?: '(بدون لوگو - آواتار حرفی)'));

        if ($banner->status === Banner::STATUS_FAILED) {
            $this->error('پیامِ خطای ذخیره‌شده روی بنر: '.$banner->error_message);
            return self::FAILURE;
        }

        if (empty($banner->html)) {
            $this->error('بنر status=generated داره ولی html خالیه — این خودش یه باگه، بررسی‌اش کنید.');
            return self::FAILURE;
        }

        $outputPath = storage_path("app/banner_test_article_{$article->id}.html");

        // یه فایلِ کاملِ HTML با یه پس‌زمینه‌ی خاکستری دورش می‌سازیم تا
        // بشه دید بنر روی زمینه‌های مختلف چطور به‌نظر می‌رسه.
        $wrapped = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تست بنر - مقاله {$article->id}</title>
<style>
  body { background:#e9ecef; padding:40px; font-family:system-ui,sans-serif; }
  .row { margin-bottom:24px; }
  .row.dark { background:#111; padding:24px; border-radius:12px; }
  .row.light { background:#fff; padding:24px; border-radius:12px; }
  p.label { color:#666; font-size:13px; margin-bottom:8px; }
</style>
</head>
<body>
  <div class="row light">
    <p class="label">روی زمینه‌ی روشن:</p>
    {$banner->html}
  </div>
  <div class="row dark">
    <p class="label" style="color:#aaa;">روی زمینه‌ی تیره:</p>
    {$banner->html}
  </div>
</body>
</html>
HTML;

        file_put_contents($outputPath, $wrapped);

        $this->newLine();
        $this->info("خروجی ذخیره شد در: {$outputPath}");
        $this->line('این فایل رو دانلود/کپی کن و مستقیم تو مرورگر باز کن تا بنر رو روی زمینه‌ی روشن و تیره ببینی.');
        $this->newLine();
        $this->line('--- خودِ کدِ HTML ---');
        $this->line($banner->html);

        return self::SUCCESS;
    }
}
