<?php

namespace App\Jobs\Products;

use App\Models\ProductArticle;
use App\Models\ProductArticleSection;
use App\Services\Products\ProductArticleWriterService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateProductArticleMetaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public ProductArticle $article) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('generate-product-article-meta-'.$this->article->id))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(ProductArticleWriterService $writer, SubscriptionService $subscriptionService): void
    {
        $this->article->refresh();

        app()->bind('current_ai_user', fn () => $this->article->site->user);

        if (! is_null($this->article->meta_generated_at)) {
            Log::info('ai.generate_product_article_meta_job.already_done_skipping', [
                'article_id' => $this->article->id,
            ]);

            return;
        }

        $pending = $this->article->sections()
            ->where('status', '!=', ProductArticleSection::STATUS_GENERATED)
            ->count();

        if ($pending > 0) {
            Log::warning('ai.generate_product_article_meta_job.skipped_pending_sections', [
                'article_id' => $this->article->id,
                'pending_count' => $pending,
            ]);

            return;
        }

        if (! $subscriptionService->hasAvailableCredit($this->article->site->user)) {
            Log::warning('ai.generate_product_article_meta_job.skipped_insufficient_credit', [
                'article_id' => $this->article->id,
            ]);

            return;
        }

        $this->article->update(['status' => ProductArticle::STATUS_GENERATING_META]);

        // ---------- مرحله ۱: تولید متا با AI ----------
        try {
            $body = $writer->assemble($this->article);

            // فقط در حافظه: original را sync می‌کنیم تا dirty نباشد و update بعدی آن را ذخیره نکند.
            $this->article->setAttribute('content', "# {$this->article->title}\n\n{$body}");
            $this->article->syncOriginalAttribute('content');

            $meta = $writer->generateMetaFields($this->article);
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('ai.generate_product_article_meta_job.ai_failed', [
                'article_id' => $this->article->id,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            // تا وقتی attempt باقی است، به صف برگردد؛ در attempt آخر مقاله بدون متا ادامه می‌دهد.
            if ($this->attempts() < $this->tries) {
                throw $exception;
            }

            return;
        }

        // ---------- مرحله ۲: ذخیره (بدون صدا زدن دوباره‌ی AI) ----------
        try {
            retry(3, fn () => $this->article->update([
                'meta_title' => $meta['meta_title'] ?? null,
                'meta_description' => $meta['meta_description'] ?? $this->article->meta_description,
                'excerpt' => $meta['excerpt'] ?? null,
                'focus_keyword' => $meta['focus_keyword'] ?? null,
                'slug' => $meta['slug'] ?? null,
                'og_title' => $meta['og_title'] ?? null,
                'og_description' => $meta['og_description'] ?? null,
                'faq_schema' => $meta['faq_schema'] ?? null,
                'product_schema' => $meta['product_schema'] ?? null,
                'meta_generated_at' => now(),
            ]), 200);
        } catch (Throwable $exception) {
            report($exception);

            Log::error('ai.generate_product_article_meta_job.save_failed', [
                'article_id' => $this->article->id,
                'error' => $exception->getMessage(),
            ]);

            // meta_generated_at ست نمی‌شود؛ مقاله بدون متا ادامه پیدا می‌کند.
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
