<?php

namespace App\Jobs;

use App\Jobs\Products\SyncSiteProductsJob;
use App\Models\Site;
use App\Models\WebsiteEntity;
use App\Services\AI\SiteAnalysisService;
use App\Services\Crawler\SiteCrawlerService;
use App\Services\WordPressTaxonomyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(
        SiteCrawlerService $crawler,
        SiteAnalysisService $analysisService,
        WordPressTaxonomyService $wordPressTaxonomyService,
    ): void {
        $this->site->refresh();

        if ($this->site->status === Site::STATUS_COMPLETED) {
            return;
        }

        app()->bind('current_ai_user', fn () => $this->site->user);

        try {
            $this->site->markAs(Site::STATUS_CRAWLING);

            // crawl() آرایه‌ای با دو کلید برمی‌گرداند: pages و navigation_tree
            $crawlResult = $crawler->crawl($this->site->url);
            $pages = $crawlResult['pages'];
            $navigationTree = $crawlResult['navigation_tree'];

            // توجه: ستون navigation_tree باید روی جدول sites وجود داشته باشد
            // (مثلاً ->longText('navigation_tree')->nullable()).
            $this->site->update([
                'crawled_pages' => $pages,
                'navigation_tree' => $navigationTree,
            ]);

            $this->site->markAs(Site::STATUS_ANALYZING);

            $aiResponse = $analysisService->analyze($this->site->domain, $pages, $navigationTree, $this->site->url);
$this->site->update([
    'status' => Site::STATUS_COMPLETED,
    'niche' => $aiResponse['niche'],
    'summary' => $aiResponse['summary'],
    'keywords' => $aiResponse['keywords'] ?? [],
    'is_shop' => (bool) ($aiResponse['is_shop'] ?? false),
    // زبان نهایی (کد ISO) که SiteAnalysisService از ترکیب محتوا + lang سایت + AI تشخیص داده
    'language' => $aiResponse['language'] ?? \App\Support\ContentLanguage::DEFAULT,
    'language_confidence' => $aiResponse['language_confidence'] ?? null,
    'language_source' => $aiResponse['language_source'] ?? null,
    'ai_response' => $aiResponse,
    'error_message' => null,
    'analyzed_at' => now(),
]);
            WebsiteEntity::updateOrCreate(
                ['website_id' => $this->site->id],
                [
                    'primary_entity' => $aiResponse['primary_entity'] ?? null,
                    'core_entities' => $aiResponse['core_entities'] ?? [],
                ]
            );

            // واکشیِ categories/tags و مقاله‌های اخیر وردپرس (غیرفatal)
            $this->syncWordpressArticles($wordPressTaxonomyService);

            // بعد از نهایی شدن آنالیز: تشخیص وردپرس/ووکامرس و همگام‌سازی محصولات (غیرفatal)
            $this->dispatchProductSync();
        } catch (Throwable $exception) {
            // فقط وقتی این آخرین تلاشه، status رو FAILED کن.
            if ($this->attempts() >= $this->tries) {
                $this->site->update([
                    'status' => Site::STATUS_FAILED,
                    'error_message' => $this->safeMessage($exception),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * sync مقالات/تکسونومی‌های وردپرس در try/catch مستقل؛ خطا فقط لاگ می‌شود
     * و روی status نهایی سایت اثر نمی‌گذارد.
     */
    protected function syncWordpressArticles(WordPressTaxonomyService $wordPressTaxonomyService): void
    {
        try {
            $result = $wordPressTaxonomyService->syncSiteWordpressData($this->site);

            Log::info('AnalyzeSiteJob: wordpress data synced', [
                'site_id' => $this->site->id,
                'categories_count' => $result['categories_count'],
                'tags_count' => $result['tags_count'],
                'posts_count' => $result['posts_count'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('AnalyzeSiteJob: wordpress data sync failed (non-fatal)', [
                'site_id' => $this->site->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * job تشخیص وردپرس/ووکامرس و همگام‌سازی محصولات را dispatch می‌کند.
     * در try/catch مستقل است تا خطای dispatch (مثلاً قطع بودن Redis)
     * وضعیت COMPLETED آنالیز را خراب نکند و باعث retry کل job نشود.
     */
    protected function dispatchProductSync(): void
    {
        try {

SyncSiteProductsJob::dispatch($this->site->id, force: true);



        } catch (Throwable $exception) {
            Log::warning('AnalyzeSiteJob: dispatch of product sync failed (non-fatal)', [
                'site_id' => $this->site->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * توسط خود سیستم صف صدا زده می‌شود (حتی بعد از timeout).
     */
    public function failed(?Throwable $exception): void
    {
        $this->site->refresh();

        if ($this->site->status === Site::STATUS_COMPLETED) {
            return;
        }

        $this->site->update([
            'status' => Site::STATUS_FAILED,
            'error_message' => $exception
                ? $this->safeMessage($exception)
                : 'Processing took too long.',
        ]);
    }

    protected function safeMessage(Throwable $exception): string
    {
        if ($exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException) {
            return $exception->getMessage();
        }

        report($exception);

        return 'Site analysis failed. Please try again.';
    }
}
