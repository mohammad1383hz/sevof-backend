<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditException;
use App\Models\Article;
use App\Models\ArticleSection;
use App\Services\AI\ArticleWriterService;
use App\Services\AI\ChatModelResolver;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StartArticleGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public Article $article) {}

    /**
     * قفل atomic مستقل از تنظیمات صف: اگر به هر دلیلی (dispatch تصادفی دوباره،
     * ری‌استارت worker وسط اجرا، تنظیم اشتباه visibility timeout) دو نسخه از
     * این Job برای همین مقاله هم‌زمان اجرا شوند، این middleware جلوی دومی را
     * می‌گیرد. expireAfter کمی بیشتر از $timeout است تا اگر Worker واقعاً کرش
     * کند (نه فقط کند باشد)، قفل برای همیشه گیر نکند.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('start-article-generation-'.$this->article->id))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(ArticleWriterService $writer, SubscriptionService $subscriptionService): void
    {
        app()->bind('current_ai_user', fn () => $this->article->site->user);

        $user = $this->article->site->user;

        // مرحله ۲: Brief (اگر قبلاً ساخته شده — مثلاً retry — دوباره نمی‌سازیم)
        if (! $this->article->brief) {
            if (! $subscriptionService->hasAvailableCredit($user)) {
                $this->article->markPausedForCredit();

                return;
            }

            $this->article->update(['status' => Article::STATUS_CREATING_BRIEF]);
            $brief = $writer->createBrief($this->article);

            // ذخیره‌ی فوری با چند بار تلاش داخلی (بدون نیاز به صدا زدن دوباره‌ی AI)
            // تا یک خطای گذرای دیتابیس باعث نشود کل Job دوباره اجرا شود.
            retry(3, fn () => $this->article->update(['brief' => $brief]), 200);
        }

        // مرحله ۳: Outline
        $outlineJustCompleted = false;

        if (! $this->article->outline) {
            if (! $subscriptionService->hasAvailableCredit($user)) {
                $this->article->markPausedForCredit();

                return;
            }

            // اگر از تلاش قبلی نتیجه‌ی خام outline موجود است (یعنی پولش قبلاً داده
            // شده ولی مرحله‌ی ذخیره‌ی سکشن‌ها fail شده بود)، AI دوباره صدا زده نمی‌شود.
            if ($this->article->raw_outline_result) {
                $sections = $this->article->raw_outline_result;
            } else {
                $this->article->update(['status' => Article::STATUS_GENERATING_OUTLINE]);
                $sections = $writer->generateOutline($this->article);

                // ذخیره‌ی فوری و مستقل از موفقیت مرحله‌ی بعد (INSERT چندردیفی سکشن‌ها).
                retry(3, fn () => $this->article->update(['raw_outline_result' => $sections]), 200);
            }

            // تراکنش پرریسک: اگر اینجا fail شود و Job دوباره اجرا شود، AI دوباره
            // صدا زده نمی‌شود چون raw_outline_result از قبل موجود است.
            retry(3, function () use ($sections) {
                DB::transaction(function () use ($sections) {
                    foreach ($sections as $position => $section) {
                        ArticleSection::create([
                            'article_id' => $this->article->id,
                            'position' => $position,
                            'type' => $section['type'],
                            'heading' => $section['heading'],
                            'subheadings' => $section['subheadings'],
                            'key_points' => $section['key_points'],
                            'target_words' => $section['target_words'],
                            'insert_internal_link' => $section['insert_internal_link'] ?? false,
                            'insert_brand_mention' => $section['insert_brand_mention'] ?? false,
                            'related_links_to_insert' => $section['related_links_to_insert'] ?? [],
                        ]);
                    }

                    // آخرین کار داخل تراکنش: حالا که همه سکشن‌ها سالم ساخته شدند، outline را ثبت کن
                    $this->article->update(['outline' => $sections]);
                });
            }, 200);

            $outlineJustCompleted = true;
        }

        $outlineReady = $outlineJustCompleted
            || ($this->article->outline && $this->article->sections()->exists());

        if (! $outlineReady) {
            throw new RuntimeException('An outline exists but the article has no sections.');
        }

        if (! $subscriptionService->hasAvailableCredit($user)) {
            $this->article->markPausedForCredit();

            return;
        }

        $this->article->update(['status' => Article::STATUS_GENERATING_SECTIONS]);

        $this->dispatchMediaJobs();

        $selectedModel = $this->article->selected_model ?: ChatModelResolver::resolve(null, false);

        $jobs = $this->article->sections()
            ->where('status', '!=', ArticleSection::STATUS_GENERATED)
            ->orderBy('position') // ترتیب زنجیره = ترتیب مقاله تا هر بخش انتهای بخش قبلی را ببیند
            ->get()
            ->map(fn (ArticleSection $section) => new GenerateArticleSectionJob($section, $selectedModel))
            ->all();

        $jobs[] = new GenerateArticleMetaJob($this->article);
        $jobs[] = new WaitForArticleMediaJob($this->article);
        $jobs[] = new FinalizeArticleJob($this->article);

        $articleId = $this->article->id;

        Bus::chain($jobs)
            ->catch(function (Throwable $e) use ($articleId) {
                if ($e instanceof InsufficientCreditException) {
                    return;
                }

                $article = Article::find($articleId);

                if ($article && ! in_array($article->status, [
                    Article::STATUS_FAILED,
                    Article::STATUS_PAUSED_CREDIT,
                    Article::STATUS_COMPLETED,
                ], true)) {
                    $article->markFailed($e->getMessage());
                }
            })
            ->onConnection($this->connection) // مقاله‌ی ایجنت روی صف background می‌ماند، مقاله‌ی کاربر روی default
            ->dispatch();
    }

    protected function dispatchMediaJobs(): void
    {
        $fresh = $this->article->fresh();

        if (in_array($fresh->image_status, [null, Article::IMAGE_STATUS_PENDING], true)) {
            $this->article->update([
                'image_status' => Article::IMAGE_STATUS_PENDING,
                'image_error' => null,
            ]);

            GenerateArticleImageJob::dispatch($this->article)->onConnection($this->connection);
        }

        if (in_array($fresh->banner_status, [null, Article::BANNER_STATUS_PENDING], true)) {
            GenerateArticleBannerJob::dispatch($this->article)->onConnection($this->connection);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->article->markFailed($exception?->getMessage() ?? 'Unknown error while starting article generation');
    }
}
