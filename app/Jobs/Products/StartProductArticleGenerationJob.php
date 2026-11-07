<?php

namespace App\Jobs\Products;

use App\Exceptions\InsufficientCreditException;
use App\Models\ProductArticle;
use App\Models\ProductArticleAssignment;
use App\Models\ProductArticleSection;
use App\Services\AI\ChatModelResolver;
use App\Services\Products\ProductArticleWriterService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StartProductArticleGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public ProductArticle $article) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('start-product-article-generation-'.$this->article->id))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(ProductArticleWriterService $writer, SubscriptionService $subscriptionService): void
    {
        $this->article->refresh();

        if ($this->article->status === ProductArticle::STATUS_COMPLETED) {
            return;
        }

        app()->bind('current_ai_user', fn () => $this->article->site->user);

        $user = $this->article->site->user;

        // مرحله ۱: Brief (در retry دوباره ساخته نمی‌شود)
        if (! $this->article->brief) {
            if (! $subscriptionService->hasAvailableCredit($user)) {
                $this->article->markPausedForCredit();

                return;
            }

            $this->article->update([
                'status' => ProductArticle::STATUS_CREATING_BRIEF,
                'error_message' => null,
            ]);

            $brief = $writer->createBrief($this->article);

            retry(3, fn () => $this->article->update(['brief' => $brief]), 200);
        }

        // مرحله ۲: Assignmentها + Sectionها
        if (! $this->article->assignments()->exists()) {
            if (! $subscriptionService->hasAvailableCredit($user)) {
                $this->article->markPausedForCredit();

                return;
            }

            // اگر نتیجه‌ی خام قبلاً پرداخت شده ولی ذخیره‌ی ردیف‌ها fail شده، AI دوباره صدا زده نمی‌شود.
            if ($this->article->raw_assignments_result) {
                $assignments = $this->article->raw_assignments_result;
            } else {
                $this->article->update(['status' => ProductArticle::STATUS_CREATING_ASSIGNMENTS]);

                $assignments = $writer->createAssignments($this->article);

                retry(3, fn () => $this->article->update(['raw_assignments_result' => $assignments]), 200);
            }

            retry(3, function () use ($assignments) {
                DB::transaction(function () use ($assignments) {
                    foreach ($assignments as $a) {
                        $assignment = ProductArticleAssignment::create([
                            'product_article_id' => $this->article->id,
                            'position' => $a['position'],
                            'type' => $a['type'],
                            'heading' => $a['heading'],
                            'subheadings' => $a['subheadings'],
                            'key_points' => $a['key_points'],
                            'target_words' => $a['target_words'],
                            'product_ids' => $a['product_ids'],
                            'status' => ProductArticleAssignment::STATUS_PENDING,
                        ]);

                        ProductArticleSection::create([
                            'product_article_id' => $this->article->id,
                            'assignment_id' => $assignment->id,
                            'position' => $a['position'],
                            'type' => $a['type'],
                            'heading' => $a['heading'],
                            'subheadings' => $a['subheadings'],
                            'key_points' => $a['key_points'],
                            'target_words' => $a['target_words'],
                            'product_ids' => $a['product_ids'],
                            'status' => ProductArticleSection::STATUS_PENDING,
                        ]);
                    }
                });
            }, 200);
        }

        if (! $this->article->sections()->exists()) {
            throw new RuntimeException('Assignments exist but the article has no sections.');
        }

        if (! $subscriptionService->hasAvailableCredit($user)) {
            $this->article->markPausedForCredit();

            return;
        }

        $this->article->update([
            'status' => ProductArticle::STATUS_GENERATING_SECTIONS,
            'error_message' => null,
        ]);

        $selectedModel = $this->article->selected_model ?: ChatModelResolver::resolve(null, false);

        // ── تصویر شاخص ──
        // فقط اگر قبلاً تولید نشده یا در حال تولید نیست، pending می‌شود
        // (در retry این job، تصویر تولیدشده دوباره پرداخت نمی‌شود).
        if (! in_array($this->article->image_status, [
            ProductArticle::IMAGE_STATUS_GENERATED,
            ProductArticle::IMAGE_STATUS_GENERATING,
        ], true)) {
            $this->article->update([
                'image_status' => ProductArticle::IMAGE_STATUS_PENDING,
                'image_error' => null,
            ]);
        }

        $jobs = [];

        // ۱) اول تصویر شاخص (بعد از brief و assignment، قبل از سکشن‌ها).
        //    این job هرگز throw نمی‌کند، پس شکستش chain را متوقف نمی‌کند.
        $jobs[] = new GenerateProductArticleImageJob($this->article);

        // ۲) سکشن‌هایی که هنوز تولید نشده‌اند
        $pendingSections = $this->article->sections()
            ->where('status', '!=', ProductArticleSection::STATUS_GENERATED)
            ->orderBy('position')
            ->get();

        foreach ($pendingSections as $section) {
            $jobs[] = new GenerateProductArticleSectionJob($section, $selectedModel);
        }

        // ۳) متا و نهایی‌سازی
        $jobs[] = new GenerateProductArticleMetaJob($this->article);
        $jobs[] = new FinalizeProductArticleJob($this->article);

        $articleId = $this->article->id;

        Bus::chain($jobs)
            ->catch(function (Throwable $e) use ($articleId) {
                if ($e instanceof InsufficientCreditException) {
                    return;
                }

                $article = ProductArticle::find($articleId);

                if ($article && ! in_array($article->status, [
                    ProductArticle::STATUS_FAILED,
                    ProductArticle::STATUS_PAUSED_CREDIT,
                    ProductArticle::STATUS_COMPLETED,
                ], true)) {
                    $article->markFailed($e->getMessage());
                }
            })
            ->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $this->article->markFailed($exception?->getMessage() ?? 'Unknown error while starting product article generation');
    }
}
