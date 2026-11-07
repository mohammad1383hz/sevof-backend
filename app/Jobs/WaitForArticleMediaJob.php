<?php
// app/Jobs/WaitForArticleMediaJob.php

namespace App\Jobs;

use App\Models\Article;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * نقطه‌ی همگام‌سازی قبل از FinalizeArticleJob.
 *
 * تصویر و بنر موازی با تولید متن اجرا می‌شوند. معمولاً تا وقتی سکشن‌ها و متا تمام می‌شوند
 * این دو هم تمام شده‌اند و این job در همان اجرای اول رد می‌شود (بدون هیچ تأخیری).
 * فقط اگر هنوز در حال تولید باشند، بدون اشغال کردن ورکر (با release) منتظر می‌ماند.
 */
class WaitForArticleMediaJob implements ShouldQueue
{
    use Queueable;

    // release هم یک attempt مصرف می‌کند؛ سقف را خودمان با MAX_ATTEMPTS کنترل می‌کنیم.
    public int $tries = 0;

    public int $timeout = 30;

    // ۱۸۰ × ۵ ثانیه = ۱۵ دقیقه؛ باید بیشتر از timeout جاب تصویر (۶۰۰ ثانیه) باشد.
    protected const MAX_ATTEMPTS = 180;

    protected const POLL_SECONDS = 5;

    public function __construct(public Article $article) {}

    public function handle(): void
    {
        $article = $this->article->fresh();

        if (! $article) {
            return;
        }

        $imageBusy = in_array($article->image_status, [Article::IMAGE_STATUS_PENDING, Article::IMAGE_STATUS_GENERATING], true);
        $bannerBusy = in_array($article->banner_status, [Article::BANNER_STATUS_PENDING, Article::BANNER_STATUS_GENERATING], true);

        // همه چیز آماده (یا صریحاً failed) است: زنجیره ادامه پیدا می‌کند و Finalize اجرا می‌شود.
        if (! $imageBusy && ! $bannerBusy) {
            return;
        }

        if ($this->attempts() < self::MAX_ATTEMPTS) {
            $this->release(self::POLL_SECONDS);

            return;
        }

        // سقف انتظار تمام شد: مقاله را گیر نینداز؛ مدیای ناتمام را failed علامت بزن و ادامه بده.
        Log::error('WaitForArticleMediaJob: gave up waiting for media', [
            'article_id' => $article->id,
            'image_status' => $article->image_status,
            'banner_status' => $article->banner_status,
        ]);

        if ($imageBusy) {
            Article::query()
                ->whereKey($article->id)
                ->whereIn('image_status', [Article::IMAGE_STATUS_PENDING, Article::IMAGE_STATUS_GENERATING])
                ->update([
                    'image_status' => Article::IMAGE_STATUS_FAILED,
                    'image_error' => 'Image generation didn\'t finish in time.',
                ]);
        }

        if ($bannerBusy) {
            Article::query()
                ->whereKey($article->id)
                ->whereIn('banner_status', [Article::BANNER_STATUS_PENDING, Article::BANNER_STATUS_GENERATING])
                ->update(['banner_status' => Article::BANNER_STATUS_FAILED]);
        }
    }
}
