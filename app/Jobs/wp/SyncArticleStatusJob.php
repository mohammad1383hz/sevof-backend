<?php

namespace App\Jobs\wp;

use App\Models\Article;
use App\Services\Wp\ArticlePublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncArticleStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public Article $article,
    ) {
    }

    public function handle(ArticlePublishService $publishService): void
    {
        // اینجا عمداً exception پرتاب نمی‌شود؛ Sync یک عملیات best-effort است
        // و نباید کاربر یا صف را با شکست آن مسدود کند.
        $publishService->syncStatus($this->article);
    }
}
