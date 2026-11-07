<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditException;
use App\Models\ArticleSection;
use App\Services\AI\ArticleWriterService;
use App\Services\AI\ChatModelResolver;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class GenerateArticleSectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public int $timeout = 300; // تولید + یک بازنویسی کیفی؛ زیر retry_after صف (۴۰۰)

    public function __construct(
        public ArticleSection $section,
        public ?string $model = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('generate-article-section-'.$this->section->id))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(ArticleWriterService $writer, SubscriptionService $subscriptionService): void
    {
        app()->bind('current_ai_user', fn () => $this->section->article->site->user);

        // idempotent: اگر در retry قبلاً تولید شده، رد شو
        if ($this->section->status === ArticleSection::STATUS_GENERATED) {
            return;
        }

        $user = $this->section->article->site->user;

        if (! $subscriptionService->hasAvailableCredit($user)) {
            $this->fail(new InsufficientCreditException());

            return;
        }

        $model = $this->model
            ?? $this->section->article->selected_model
            ?? ChatModelResolver::resolve(null, false);

        $content = $writer->generateSection($this->section, $model);

        // ذخیره‌ی فوری با چند بار تلاش داخلی: اگر یک خطای گذرای دیتابیس همین‌جا
        // رخ دهد، Job دوباره از صف نمی‌آید و AI دوباره صدا زده نمی‌شود.
        retry(3, fn () => $this->section->update([
            'content' => $content,
            'status' => ArticleSection::STATUS_GENERATED,
            'error_message' => null,
        ]), 200);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof InsufficientCreditException) {
            $this->section->article->markPausedForCredit($exception->getMessage());

            return;
        }

        $message = $exception?->getMessage() ?? 'Unknown error';

        $this->section->update([
            'status' => ArticleSection::STATUS_FAILED,
            'error_message' => $message,
        ]);

        $this->section->article->markFailed("تولید بخش «{$this->section->heading}» ناموفق بود: {$message}");
    }
}
