<?php

namespace App\Jobs\AgentApi;

use App\Models\Article;
use App\Models\ArticleGenerationTask;
use App\Services\AI\ArticleWriterService;
use App\Services\CloudflareImageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class PublicAgentGenerateImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 240;

    public function __construct(public Article $article, public ArticleGenerationTask $task) {}

    public function handle(ArticleWriterService $writer, CloudflareImageService $imageService): void
    {
        app()->bind('current_ai_user', fn () => $this->article->site->user);
        $this->article->update(['image_status' => 'generating']);

        try {
            $sections = $this->article->sections()->orderBy('position')->get();

            // فقط یک تصویر (شاخص) می‌خواهیم
            $images = $writer->generateImagePrompts(
                article: $this->article,
                sections: $sections,
                imageCount: 1,
                imageStyle: 'realistic',
                modelId: null,
                featuredUserPrompt: null,
            );

            $promptResult = $images[0] ?? [];
            $prompt = trim($promptResult['image_prompt'] ?? '');

            if ($prompt === '') {
                throw new RuntimeException('Image prompt is empty.');
            }

            $this->article->update([
                'image_prompt' => $prompt,
                'image_alt' => trim($promptResult['alt_text'] ?? ''),
            ]);

            $result = $imageService->generate($prompt);

            $this->article->update([
                'image_path' => $result['path'],
                'image_url' => $result['url'],
                'image_status' => 'generated',
                'image_error' => null,
            ]);

            PublicAgentFinalizeTaskJob::dispatch($this->article, $this->task);
        } catch (Throwable $exception) {
            $this->article->update(['image_status' => 'failed', 'image_error' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->article->update(['image_status' => 'failed', 'image_error' => $exception?->getMessage()]);
        $this->task->forceFill([
            'status' => ArticleGenerationTask::STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
        ])->save();
    }
}
