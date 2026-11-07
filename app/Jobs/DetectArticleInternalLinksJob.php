<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\WordpressArticle;
use App\Services\RelatedArticleLinkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DetectArticleInternalLinksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // یک AI call سبک برای کل batch زده می‌شه (نه دیگه کاملاً local مثل قبل)؛
    // timeout رو یک‌کم بالاتر از حالت local-only نگه می‌داریم.
    public int $timeout = 180;

    public function __construct(
        public Site $site,
        public int $batch,
    ) {}

    public function handle(RelatedArticleLinkService $linkService): void
    {
        $this->site->update([
            'idea_generation_status' => 'linking_articles',
            'idea_generation_error' => null,
        ]);

        // اگه سایت اصلاً هیچ مقاله وردپرسی‌ای sync نکرده (وردپرسی نیست، یا هنوز
        // پست ندارد، یا REST API‌اش در دسترس نبوده)، کل مرحله رو بی‌سر و صدا
        // skip می‌کنیم — نه خطا، نه AI call، نه query اضافه.
        $hasWordpressArticles = WordpressArticle::where('site_id', $this->site->id)->exists();

        if (! $hasWordpressArticles) {
            Log::info('DetectArticleInternalLinksJob: no wordpress articles for site, skipping link detection', [
                'site_id' => $this->site->id,
            ]);

            $this->site->update(['idea_generation_status' => 'done']);

            return;
        }

        $ideas = $this->site->articleIdeas()
            ->where('batch', $this->batch)
            ->get();

        if ($ideas->isEmpty()) {
            $this->site->update(['idea_generation_status' => 'done']);

            return;
        }

        try {
            // یک فراخوانی برای کل batch: هم query تکراری به WordpressArticle حذف می‌شه،
            // هم AI call (اگه لازم باشه) به‌جای N بار، یک بار زده می‌شه.
            $results = $linkService->attachForSite($this->site, $ideas);

            Log::info('DetectArticleInternalLinksJob: link detection finished', [
                'site_id' => $this->site->id,
                'batch' => $this->batch,
                'links_per_idea' => $results,
            ]);
        } catch (Throwable $exception) {
            // non-fatal: اگه به هر دلیلی کل مرحله شکست خورد، idea ها بدون لینک مرتبط
            // باقی می‌مونن ولی pipeline متوقف نمی‌شه.
            Log::warning('DetectArticleInternalLinksJob: linking failed for batch', [
                'site_id' => $this->site->id,
                'batch' => $this->batch,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->site->update(['idea_generation_status' => 'done']);
    }
}
