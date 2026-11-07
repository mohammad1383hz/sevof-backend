<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\ArticlePublisherResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // انتشار POST است و ممکن است قبل از timeout به مقصد رسیده باشد؛ پس retry خودکار نداریم.
    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        public Article $article,
        public string $action = 'publish',   // publish | update
    ) {
    }

    public function handle(ArticlePublisherResolver $resolver): void
    {
        $publisher = $resolver->for($this->article);

        $this->action === 'update'
            ? $publisher->update($this->article)
            : $publisher->create($this->article);
    }
}
