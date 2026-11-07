<?php

namespace App\Jobs\wp;

use App\Models\ProductArticle;
use App\Services\Wp\ProductArticlePublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishProductArticleToWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public ProductArticle $article,
        public string $action = 'publish', // publish | update
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ProductArticlePublishService $publishService): void
    {
        $result = $this->action === 'update'
            ? $publishService->updateProductArticle($this->article)
            : $publishService->createProductArticle($this->article);

        if (! $result['ok']) {
            throw new \RuntimeException($result['message'] ?? 'Unknown error while publishing the product article.');
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
