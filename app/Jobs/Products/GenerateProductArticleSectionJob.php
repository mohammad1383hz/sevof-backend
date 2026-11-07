<?php

namespace App\Jobs\Products;

use App\Exceptions\InsufficientCreditException;
use App\Models\ProductArticleAssignment;
use App\Models\ProductArticleSection;
use App\Services\AI\ChatModelResolver;
use App\Services\Products\ProductArticleWriterService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class GenerateProductArticleSectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public int $timeout = 300; // تولید + یک بازنویسی کیفی؛ زیر retry_after صف (۴۰۰)

    public function __construct(
        public ProductArticleSection $section,
        public ?string $model = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('generate-product-article-section-'.$this->section->id))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(ProductArticleWriterService $writer, SubscriptionService $subscriptionService): void
    {
        $this->section->refresh();

        // idempotent: اگر در retry قبلاً تولید شده، رد شو
        if ($this->section->status === ProductArticleSection::STATUS_GENERATED) {
            return;
        }

        $article = $this->section->article;

        app()->bind('current_ai_user', fn () => $article->site->user);

        if (! $subscriptionService->hasAvailableCredit($article->site->user)) {
            $this->fail(new InsufficientCreditException());

            return;
        }

        $model = $this->model
            ?? $article->selected_model
            ?? ChatModelResolver::resolve(null, false);

        $content = $writer->generateSection($this->section, $model);

        // ذخیره‌ی فوری با چند بار تلاش داخلی تا AI دوباره صدا زده نشود
        retry(3, function () use ($content) {
            $this->section->update([
                'content' => $content,
                'status' => ProductArticleSection::STATUS_GENERATED,
                'error_message' => null,
            ]);

            ProductArticleAssignment::whereKey($this->section->assignment_id)
                ->update(['status' => ProductArticleAssignment::STATUS_GENERATED]);
        }, 200);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof InsufficientCreditException) {
            $this->section->article->markPausedForCredit($exception->getMessage());

            return;
        }

        $message = $exception?->getMessage() ?? 'Unknown error';

        $this->section->update([
            'status' => ProductArticleSection::STATUS_FAILED,
            'error_message' => $message,
        ]);

        ProductArticleAssignment::whereKey($this->section->assignment_id)
            ->update(['status' => ProductArticleAssignment::STATUS_FAILED]);

        $this->section->article->markFailed("تولید بخش «{$this->section->heading}» ناموفق بود: {$message}");
    }
}
