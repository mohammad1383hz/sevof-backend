
namespace App\Jobs\wp;

use App\Models\Article;
use App\Services\Wp\ArticlePublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeleteArticleFromWordPressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Article $article,
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ArticlePublishService $publishService): void
    {
        $result = $publishService->delete($this->article);

        if (! $result['ok']) {
            throw new \RuntimeException($result['message'] ?? 'Unknown error while deleting the article.');
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->article->update([
            'publish_status' => 'failed',
            'publish_error' => $exception->getMessage(),
        ]);
    }
}
