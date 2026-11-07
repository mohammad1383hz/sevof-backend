<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleSection;
use App\Services\AI\ArticleWriterService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateArticleMetaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public Article $article) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('generate-article-meta-'.$this->article->id))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(ArticleWriterService $writer, SubscriptionService $subscriptionService): void
    {
        app()->bind('current_ai_user', fn () => $this->article->site->user);

        // این مرحله قبلاً با موفقیت کامل انجام شده؛ دوباره AI صدا زده نشود.
        if (! is_null($this->article->meta_generated_at)) {
            Log::info('ai.generate_article_meta_job.already_done_skipping', [
                'article_id' => $this->article->id,
            ]);

            return;
        }

        $sections = $this->article->sections()->get();

        $pending = $sections->where('status', '!=', ArticleSection::STATUS_GENERATED);

        if ($pending->isNotEmpty()) {
            Log::warning('ai.generate_article_meta_job.skipped_pending_sections', [
                'article_id' => $this->article->id,
                'pending_count' => $pending->count(),
            ]);

            return;
        }

        if (! $subscriptionService->hasAvailableCredit($this->article->site->user)) {
            Log::warning('ai.generate_article_meta_job.skipped_insufficient_credit', [
                'article_id' => $this->article->id,
            ]);

            return;
        }

        $this->article->update(['status' => Article::STATUS_GENERATING_META]);

        $body = $sections->map(function (ArticleSection $section) {
            return $section->type === 'introduction'
                ? $section->content
                : "## {$section->heading}\n\n{$section->content}";
        })->implode("\n\n");

        $this->article->content = "# {$this->article->title}\n\n{$body}";

        try {
            $meta = $writer->generateMetaFields($this->article);

            // ذخیره‌ی فوری با چند بار تلاش داخلی: اگر یک خطای گذرای دیتابیس همین‌جا
            // رخ دهد، Job دوباره از صف نمی‌آید و AI دوباره صدا زده نمی‌شود.
            retry(3, fn () => $this->article->update([
                'meta_title' => $meta['meta_title'] ?? null,
                'meta_description' => $meta['meta_description'] ?? $this->article->meta_description,
                'excerpt' => $meta['excerpt'] ?? null,
                'focus_keyword' => $meta['focus_keyword'] ?? null,
                'slug' => $meta['slug'] ?? null,
                'og_title' => $meta['og_title'] ?? null,
                'og_description' => $meta['og_description'] ?? null,
                'faq_schema' => $meta['faq_schema'] ?? null,
                'meta_generated_at' => now(),
            ]), 200);
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('ai.generate_article_meta_job.failed_but_continuing', [
                'article_id' => $this->article->id,
                'error' => $exception->getMessage(),
            ]);

            // meta_generated_at عمداً ست نمی‌شود → دفعه‌ی بعد واقعاً دوباره تلاش
            // می‌شود، چون این‌بار واقعاً fail شده، نه اینکه فقط job قطع شده باشد.
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
