<?php

namespace App\Jobs\Products;

use App\Models\ProductArticle;
use App\Models\ProductArticleSection;
use App\Services\Products\ProductArticleWriterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Throwable;

class FinalizeProductArticleJob implements ShouldQueue
{
    use Queueable;

    // release شدن منتظر تصویر هم یک attempt حساب می‌شود، پس سقف بالاتر است.
    public int $tries = 10;

    public array $backoff = [15];

    public int $timeout = 120;

    public function __construct(public ProductArticle $article) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('finalize-product-article-'.$this->article->id))
                ->expireAfter($this->timeout + 30)
                ->dontRelease(),
        ];
    }

    public function handle(ProductArticleWriterService $writer): void
    {
        $this->article->refresh();

        if ($this->article->status === ProductArticle::STATUS_COMPLETED) {
            return;
        }

        $pending = $this->article->sections()
            ->where('status', '!=', ProductArticleSection::STATUS_GENERATED)
            ->get(['heading']);

        if ($pending->isNotEmpty()) {
            throw new RuntimeException(
                'These sections haven\'t been generated yet: '.$pending->pluck('heading')->implode('، ')
            );
        }

        // تا وقتی تصویر شاخص در حال ساخت است، مقاله completed نمی‌شود.
        // (در حالت عادی با ترتیب chain هرگز اینجا نمی‌رسیم؛ فقط گارد ایمنی است.)
        if (in_array($this->article->image_status, [
            ProductArticle::IMAGE_STATUS_PENDING,
            ProductArticle::IMAGE_STATUS_GENERATING,
        ], true)) {
            $this->release(15);

            return;
        }

        $body = $writer->assemble($this->article);
        $content = "# {$this->article->title}\n\n{$body}";

        // شمارش کلمات روی متن ساده (بدون تصویر، URL لینک و علائم Markdown/جدول)
        $plain = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $content);
        $plain = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', (string) $plain);
        $plain = trim((string) preg_replace('/[#*`>|\-]+|\s+/u', ' ', strip_tags((string) $plain)));
        $wordCount = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY));

        retry(3, fn () => $this->article->update([
            'content' => $content,
            'word_count' => $wordCount,
            'status' => ProductArticle::STATUS_COMPLETED,
            'completed_at' => now(),
            'error_message' => null,
        ]), 200);
    }

    public function failed(?Throwable $exception): void
    {
        $this->article->markFailed($exception?->getMessage() ?? 'Unknown error while finalizing the product article');
    }
}
