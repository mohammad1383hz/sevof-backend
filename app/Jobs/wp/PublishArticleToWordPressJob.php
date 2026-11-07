<?php

namespace App\Jobs\wp;

use App\Models\Article;
use App\Services\Wp\ArticlePublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * یک Job برای هر دو حالت publish و update — چون از نظر منطقی یک عملیات
 * "ارسال مقاله به وردپرس" هستند و فقط اکشن‌شان فرق دارد.
 * Retry با مکانیزم داخلی صف لاراول (tries + backoff) انجام می‌شود.
 */
class PublishArticleToWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Article $article,
        public string $action = 'publish', // publish | update
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ArticlePublishService $publishService): void
    {
        $result = $this->action === 'update'
            ? $publishService->update($this->article)
            : $publishService->create($this->article);

        if (! $result['ok']) {
            throw new \RuntimeException($result['message'] ?? 'Unknown error while publishing the article.');
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->article->update([
            'wp_sync_status' => 'failed',
            'publish_error' => $exception->getMessage(),
        ]);
    }
}
