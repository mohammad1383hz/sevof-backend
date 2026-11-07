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
use RuntimeException;
use Throwable;

class FinalizeArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 240;

    protected const MAX_IMAGE_WAIT_ATTEMPTS = 40;

    protected const IMAGE_WAIT_DELAY_SECONDS = 15;

    public function __construct(
        public Article $article,
        protected int $imageWaitAttempt = 0,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('finalize-article-'.$this->article->id))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(ArticleWriterService $writer, SubscriptionService $subscriptionService): void
    {
        app()->bind('current_ai_user', fn () => $this->article->site->user);

        $this->article->refresh();

        $sections = $this->article->sections()->orderBy('position')->get();
        $pending = $sections->where('status', '!=', ArticleSection::STATUS_GENERATED);

        if ($pending->isNotEmpty()) {
            throw new RuntimeException(
                'These sections haven\'t been generated yet: '.$pending->pluck('heading')->implode('، ')
            );
        }

        $imageState = $this->imageState();

        if ($imageState === 'waiting') {
            $this->waitForImages();

            return;
        }

        $imagesOk = $imageState === 'ok';

        $body = $sections->map(function (ArticleSection $section) {
            return $section->type === 'introduction'
                ? $section->content
                : "## {$section->heading}\n\n{$section->content}";
        })->implode("\n\n");

        $content = "# {$this->article->title}\n\n{$body}";

        $plainText = trim(strip_tags($content));
        $wordCount = $plainText === '' ? 0 : count(preg_split('/\s+/u', $plainText));

        $this->article->update([
            'content' => $content,
            'word_count' => $wordCount,
            'status' => Article::STATUS_REVIEWING,
        ]);

        if (is_null($this->article->seo_reviewed_at) && $subscriptionService->hasAvailableCredit($this->article->site->user)) {
            try {
                $review = $writer->reviewArticle($this->article);

                // ذخیره‌ی فوری با چند بار تلاش داخلی؛ seo_reviewed_at فقط بعد از
                // موفقیت کامل ست می‌شود.
                retry(3, fn () => $this->article->update([
                    'seo_score' => min(100, max(0, (int) ($review['seo_score'] ?? 0))),
                    'seo_review' => $review,
                    'seo_reviewed_at' => now(),
                ]), 200);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if (empty($this->article->wp_category_id)) {
            $taxonomy = $writer->determineCategoryAndTags($this->article);

            retry(3, fn () => $this->article->update([
                'wp_category_id' => $taxonomy['category_id'],
                'wp_category_name' => $taxonomy['category_name'],
                'wp_tag_ids' => $taxonomy['tag_ids'],
                'wp_new_tags' => $taxonomy['new_tags'],
            ]), 200);
        }

        $this->article->update([
            'status' => Article::STATUS_COMPLETED,
            'completed_at' => now(),
            'error_message' => null,
        ]);

        $this->article->idea?->update(['status' => 'used']);

        if ($this->article->source === Article::SOURCE_AGENT) {
            if ($imagesOk) {
                PublishArticleJob::dispatch($this->article, 'publish');
            } else {
                Log::warning('FinalizeArticleJob: auto-publish skipped, images not valid', [
                    'article_id' => $this->article->id,
                    'image_status' => $this->article->image_status,
                    'image_error' => $this->article->image_error,
                ]);
            }
        }
    }

    protected function imageState(): string
    {
        $status = $this->article->image_status;

        if (! $status || $status === Article::IMAGE_STATUS_PENDING || $status === 'generating') {
            return 'waiting';
        }

        if ($status === 'failed') {
            return 'invalid';
        }

        $problems = $this->validateImages();

        if ($problems !== []) {
            Log::warning('FinalizeArticleJob: image validation failed', [
                'article_id' => $this->article->id,
                'problems' => $problems,
            ]);

            return 'invalid';
        }

        return 'ok';
    }

    protected function validateImages(): array
    {
        $problems = [];

        if (empty($this->article->image_url) || empty($this->article->image_path)) {
            $problems[] = 'The featured image has no url/path.';
        } elseif (! is_file($this->article->image_path)) {
            $problems[] = 'The featured image file is missing on disk.';
        }

        $expectedGallery = max(0, ((int) $this->article->image_count) - 1);
        $gallery = $this->article->image_gallery;
        $gallery = is_array($gallery) ? $gallery : [];

        if (count($gallery) < $expectedGallery) {
            $problems[] = 'Fewer gallery images than expected ('.count($gallery)."/{$expectedGallery}).";
        }

        foreach ($gallery as $index => $item) {
            $url = is_array($item) ? trim((string) ($item['url'] ?? '')) : '';
            $path = is_array($item) ? trim((string) ($item['path'] ?? '')) : '';

            if ($url === '' || $path === '') {
                $problems[] = "گالری آیتم {$index}: url یا path خالی است.";

                continue;
            }

            if (! is_file($path)) {
                $problems[] = "گالری آیتم {$index}: فایل روی دیسک وجود ندارد.";
            }

            if (! isset($item['after_section']) || $item['after_section'] === '') {
                $problems[] = "گالری آیتم {$index}: after_section تعیین نشده.";
            }
        }

        return $problems;
    }

    protected function waitForImages(): void
    {
        if ($this->imageWaitAttempt >= self::MAX_IMAGE_WAIT_ATTEMPTS) {
            Log::error('FinalizeArticleJob: gave up waiting for images', [
                'article_id' => $this->article->id,
                'image_status' => $this->article->image_status,
            ]);

            $this->article->markFailed('Article images were not finished in time.');

            return;
        }

        static::dispatch($this->article, $this->imageWaitAttempt + 1)
            ->delay(now()->addSeconds(self::IMAGE_WAIT_DELAY_SECONDS));
    }

    public function failed(?Throwable $exception): void
    {
        $this->article->markFailed($exception?->getMessage() ?? 'Unknown error while finalizing the article');
    }
}
