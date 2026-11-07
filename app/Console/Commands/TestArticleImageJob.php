<?php

// app/Console/Commands/TestArticleImageJob.php

namespace App\Console\Commands;

use App\Jobs\GenerateArticleImageJob;
use App\Models\Article;
use Illuminate\Console\Command;

class TestArticleImageJob extends Command
{
    protected $signature = 'article:test-image
                            {id : آیدی مقاله}
                            {--queue : به‌جای اجرای مستقیم، روی صف dispatch شود}
                            {--keep : وضعیت و تصاویر قبلی ریست نشوند (فقط اگر image_status از قبل pending باشد کار می‌کند)}';

    protected $description = 'اجرای GenerateArticleImageJob برای یک مقاله‌ی مشخص (برای تست)';

    public function handle(): int
    {
        $article = Article::find($this->argument('id'));

        if (! $article) {
            $this->error('مقاله پیدا نشد.');

            return self::FAILURE;
        }

        if (! $article->sections()->exists()) {
            $this->error('این مقاله سکشن ندارد؛ job تصویر به سکشن‌ها نیاز دارد.');

            return self::FAILURE;
        }

        if (! $this->option('keep')) {
            $article->update([
                'image_status' => Article::IMAGE_STATUS_PENDING,
                'image_error' => null,
                'image_path' => null,
                'image_url' => null,
                'image_gallery' => [],
            ]);

            $this->info('وضعیت تصویر به pending ریست شد.');
        }

        if ($this->option('queue')) {
            GenerateArticleImageJob::dispatch($article);
            $this->info('Job روی صف رفت. لاگ worker را ببین.');

            return self::SUCCESS;
        }

        $this->info("اجرای مستقیم job برای مقاله #{$article->id} ...");

        $start = microtime(true);

        try {
            GenerateArticleImageJob::dispatchSync($article);
        } catch (\Throwable $e) {
            $this->error('Job exception داد: '.$e->getMessage());
        }

        $article->refresh();

        $this->newLine();
        $this->line('مدت اجرا: '.round(microtime(true) - $start, 1).' ثانیه');
        $this->line('image_status: '.$article->image_status);
        $this->line('image_model : '.$article->image_model);
        $this->line('image_count : '.$article->image_count);
        $this->line('image_url   : '.($article->image_url ?? '-'));

        if ($article->image_error) {
            $this->warn('image_error : '.$article->image_error);
        }

        $gallery = $article->image_gallery;
        $gallery = is_string($gallery) ? (json_decode($gallery, true) ?: []) : ($gallery ?? []);

        if ($gallery) {
            $this->newLine();
            $this->table(
                ['order', 'status', 'url', 'error'],
                array_map(fn ($g) => [
                    $g['order'] ?? '-',
                    $g['status'] ?? '-',
                    $g['url'] ?? '-',
                    $g['error'] ?? '-',
                ], $gallery)
            );
        }

        return $article->image_status === Article::IMAGE_STATUS_GENERATED
            ? self::SUCCESS
            : self::FAILURE;
    }
}
