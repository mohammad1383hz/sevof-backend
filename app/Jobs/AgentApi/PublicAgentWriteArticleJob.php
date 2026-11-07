<?php

namespace App\Jobs\AgentApi;

use App\Jobs\AgentApi\PublicAgentGenerateBannerJob;
use App\Models\Article;
use App\Models\ArticleGenerationTask;
use App\Models\ArticleSection;
use App\Services\AI\ArticleWriterService;
use App\Services\AI\ChatModelResolver;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class PublicAgentWriteArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public Article $article, public ArticleGenerationTask $task) {}

    public function handle(ArticleWriterService $writer, SubscriptionService $subscriptions): void
    {
        app()->bind('current_ai_user', fn () => $this->article->site->user);
        $user = $this->article->site->user;
        if (! $subscriptions->hasAvailableCredit($user)) throw new RuntimeException('No generation credit is available.');

        $this->article->update(['status' => Article::STATUS_CREATING_BRIEF]);
        $this->article->update(['brief' => $writer->createBrief($this->article)]);
        $this->article->update(['status' => Article::STATUS_GENERATING_OUTLINE]);
        $outline = $writer->generateOutline($this->article);
        $this->article->update(['outline' => $outline]);

        foreach ($outline as $position => $item) {
            $section = ArticleSection::create([
                'article_id' => $this->article->id,
                'position' => $position,
                'type' => $item['type'],
                'heading' => $item['heading'],
                'subheadings' => $item['subheadings'] ?? [],
                'key_points' => $item['key_points'] ?? [],
                'target_words' => $item['target_words'] ?? null,
                'insert_internal_link' => $item['insert_internal_link'] ?? false,
                'insert_brand_mention' => $item['insert_brand_mention'] ?? false,
            ]);
            $section->update([
                'content' => $writer->generateSection($section, $this->article->selected_model ?: ChatModelResolver::resolve(null, false)),
                'status' => ArticleSection::STATUS_GENERATED,
            ]);
        }

        $sections = $this->article->sections()->get();
        $body = $sections->map(fn (ArticleSection $section) => $section->type === 'introduction'
            ? $section->content : "## {$section->heading}\n\n{$section->content}")->implode("\n\n");
        $this->article->update(['status' => Article::STATUS_GENERATING_META, 'content' => "# {$this->article->title}\n\n{$body}"]);

        if ($subscriptions->hasAvailableCredit($user)) {
            $meta = $writer->generateMetaFields($this->article);
            $this->article->update([
                'meta_title' => $meta['meta_title'] ?? null,
                'meta_description' => $meta['meta_description'] ?? $this->article->meta_description,
                'excerpt' => $meta['excerpt'] ?? null,
                'focus_keyword' => $meta['focus_keyword'] ?? null,
                'slug' => $meta['slug'] ?? null,
                'og_title' => $meta['og_title'] ?? null,
                'og_description' => $meta['og_description'] ?? null,
                'faq_schema' => $meta['faq_schema'] ?? null,
            ]);

            try {
                $review = $writer->reviewArticle($this->article);
                $this->article->update([
                    'seo_score' => min(100, max(0, (int) ($review['seo_score'] ?? 0))),
                    'seo_review' => $review,
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->article->update([
            'status' => Article::STATUS_COMPLETED,
            'completed_at' => now(),
            'word_count' => str_word_count(strip_tags($this->article->content)) ?: count(preg_split('/\s+/u', trim(strip_tags($this->article->content)))),
            'error_message' => null,
        ]);
        $this->article->idea?->update(['status' => 'used']);

        // تصاویر مستقل از متن تولید می‌شوند، اما Task تا آماده‌شدن هر دو asset کامل نمی‌شود.
        PublicAgentGenerateImageJob::dispatch($this->article, $this->task);
        PublicAgentGenerateBannerJob::dispatch($this->article, $this->task);
    }

    public function failed(?Throwable $exception): void
    {
        $this->article->markFailed($exception?->getMessage() ?? 'Agent article generation failed.');
        $this->task->forceFill(['status' => ArticleGenerationTask::STATUS_FAILED, 'error_message' => $exception?->getMessage()])->save();
    }
}
