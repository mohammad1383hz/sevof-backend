<?php
// app/Jobs/GenerateArticleImageJob.php

namespace App\Jobs;

use App\Enums\ImageStyle;
use App\Models\Article;
use App\Services\AI\ArticleWriterService;
use App\Services\Image\ImageGenerationService;
use App\Services\Image\ImageModelResolver;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenerateArticleImageJob implements ShouldQueue
{
    use Queueable;

    // عمداً ۱: retry یعنی تولید و پرداخت دوباره‌ی همه‌ی تصاویر.
    public int $tries = 1;

    // سقف کل زمان job. retry_after صف و --timeout ورکر باید بزرگ‌تر از این باشند.
  public int $timeout = 480;

protected const OVERHEAD_SECONDS = 60;
protected const SAFETY_MARGIN_SECONDS = 20;
protected const UPLOAD_RESERVE_SECONDS = 10;
protected const PER_IMAGE_MIN_SECONDS = 20;
protected const PER_IMAGE_SECONDS = 70;   // جایگزین PER_IMAGE_MAX_SECONDS

    // وضعیت هر آیتم گالری
    public const GALLERY_PENDING = 'pending';

    public const GALLERY_GENERATED = 'generated';

    public const GALLERY_FAILED = 'failed';

    public const GALLERY_SKIPPED = 'skipped';

    public function __construct(public Article $article) {}

    public function handle(
        ArticleWriterService $writer,
        ImageGenerationService $imageService,
        SubscriptionService $subscriptionService,
    ): void {
        $startedAt = microtime(true);

        // Claim اتمیک: اگر job دو بار دیسپچ شده باشد، فقط یکی pending → generating می‌کند.
        $claimed = Article::query()
            ->whereKey($this->article->id)
            ->where('image_status', Article::IMAGE_STATUS_PENDING)
            ->update(['image_status' => Article::IMAGE_STATUS_GENERATING, 'image_error' => null]);

        if ($claimed === 0) {
            Log::info('GenerateArticleImageJob: already claimed/finished, skipping', [
                'article_id' => $this->article->id,
            ]);

            return;
        }

        $this->article->refresh();

        $user = $this->article->site->user;

        app()->bind('current_ai_user', fn () => $user);

        if (! $subscriptionService->hasAvailableCredit($user)) {
            $this->article->update([
                'image_status' => Article::IMAGE_STATUS_FAILED,
                'image_error' => 'Not enough credit to generate the image.',
            ]);

            return;
        }

        $sections = $this->article->sections()->orderBy('position')->get();

        if ($sections->isEmpty()) {
            throw new RuntimeException('Sections must exist before generating images.');
        }

        $isPaidUser = $subscriptionService->hasActivePaidPlan($user);

        [$modelId, $imageCount] = ImageModelResolver::resolve(
            $this->article->image_model,
            $this->article->image_count,
            $isPaidUser,
        );

        $imageStyle = $this->article->image_style instanceof ImageStyle
            ? $this->article->image_style
            : ImageStyle::resolve($this->article->image_style);

      $perImageBudget = self::PER_IMAGE_SECONDS;

        try {
$promptSets = $writer->generateImagePrompts(
    $this->article,
    $sections,
    $imageCount,
    $imageStyle->value,
    $modelId,
    featuredUserPrompt: $this->article->image_user_prompt,
);

            if (empty($promptSets[0])) {
                throw new RuntimeException('No featured image prompt was returned.');
            }

            $featuredPrompt = trim($promptSets[0]['image_prompt'] ?? '');
            $featuredAlt = trim($promptSets[0]['alt_text'] ?? '');
            $featuredFileName = $promptSets[0]['file_name'] ?? null;

            if ($featuredPrompt === '') {
                throw new RuntimeException('The featured image prompt came back empty.');
            }

            // ── اسکلت گالری: promptها همین‌جا و قبل از هر تولید تصویری ذخیره می‌شوند ──
            // کلید آرایه = ایندکس prompt، تا با promptهای خالی جابه‌جا نشود.
            $gallery = [];

            for ($i = 1; $i < $imageCount; $i++) {
                $prompt = trim($promptSets[$i]['image_prompt'] ?? '');

                if ($prompt === '') {
                    Log::warning('GenerateArticleImageJob: empty gallery prompt, skipped', [
                        'article_id' => $this->article->id,
                        'index' => $i,
                    ]);

                    continue;
                }

                $gallery[$i] = [
                    'order' => $i,
                    'prompt' => $prompt,
                    'alt' => trim($promptSets[$i]['alt_text'] ?? ''),
                    'file_name' => $promptSets[$i]['file_name'] ?? null,
                    'after_section' => $promptSets[$i]['after_section'] ?? null,
                    'path' => null,
                    'url' => null,
                    'status' => self::GALLERY_PENDING,
                    'error' => null,
                ];
            }

            $this->article->update([
                'image_prompt' => $featuredPrompt,
                'image_alt' => $featuredAlt,
                'image_model' => $modelId,
                'image_count' => $imageCount,
                'image_gallery' => array_values($gallery),
            ]);

            // ── تصویر شاخص (اجباری) ──
            $featuredBudget = max(
                self::PER_IMAGE_MIN_SECONDS,
                min($perImageBudget, $this->remainingSeconds($startedAt) - self::UPLOAD_RESERVE_SECONDS)
            );

            try {
                $featuredResult = $imageService->generate(
                    user: $user,
                    modelId: $modelId,
                    prompt: $featuredPrompt,
                    userIsPro: $isPaidUser,
                    maxWaitSeconds: $featuredBudget,
                    fileName: $featuredFileName,
                );
            } catch (Throwable $e) {
                Log::error('GenerateArticleImageJob: featured image failed', [
                    'article_id' => $this->article->id,
                    'model' => $modelId,
                    'message' => $e->getMessage(),
                ]);

                // promptهای گالری در دیتابیس می‌مانند، فقط علامت skipped می‌خورند.
                $this->markPending($gallery, self::GALLERY_SKIPPED, 'Featured image failed.');

                $this->article->update([
                    'image_status' => Article::IMAGE_STATUS_FAILED,
                    'image_error' => 'Featured image generation failed: '.$e->getMessage(),
                    'image_gallery' => array_values($gallery),
                ]);

                report($e);

                return;
            }

            $this->article->update([
                'image_path' => $featuredResult['path'],
                'image_url' => $featuredResult['url'],
            ]);

            // ── تصاویر گالری: بهترین تلاش، محدود به زمان باقی‌مانده ──
            foreach (array_keys($gallery) as $i) {
                $remaining = $this->remainingSeconds($startedAt);

                if ($remaining < self::PER_IMAGE_MIN_SECONDS + self::UPLOAD_RESERVE_SECONDS) {
                    Log::warning('GenerateArticleImageJob: time budget exhausted, remaining gallery images skipped', [
                        'article_id' => $this->article->id,
                        'stopped_at_index' => $i,
                        'requested_count' => $imageCount,
                    ]);

                    $this->markPending($gallery, self::GALLERY_SKIPPED, 'The job timed out.');
                    $this->saveGallery($gallery);

                    break;
                }

                $budget = min($perImageBudget, $remaining - self::UPLOAD_RESERVE_SECONDS);

                try {
                    $result = $imageService->generate(
                        user: $user,
                        modelId: $modelId,
                        prompt: $gallery[$i]['prompt'],
                        userIsPro: $isPaidUser,
                        maxWaitSeconds: $budget,
                        fileName: $gallery[$i]['file_name'],
                    );

                    $gallery[$i]['path'] = $result['path'];
                    $gallery[$i]['url'] = $result['url'];
                    $gallery[$i]['status'] = self::GALLERY_GENERATED;
                    $gallery[$i]['error'] = null;
                } catch (Throwable $e) {
                    Log::error('GenerateArticleImageJob: gallery image failed', [
                        'article_id' => $this->article->id,
                        'index' => $i,
                        'model' => $modelId,
                        'message' => $e->getMessage(),
                    ]);

                    $gallery[$i]['status'] = self::GALLERY_FAILED;
                    $gallery[$i]['error'] = $e->getMessage();
                }

                // ذخیره‌ی تدریجی بعد از هر تصویر (موفق یا ناموفق)
                $this->saveGallery($gallery);
            }

            $this->article->update([
                'image_status' => Article::IMAGE_STATUS_GENERATED,
                'image_error' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('GenerateArticleImageJob failed', [
                'article_id' => $this->article->id,
                'model' => $modelId,
                'requested_count' => $imageCount,
                'image_style' => $imageStyle->value,
                'message' => $exception->getMessage(),
            ]);

            $this->article->update([
                'image_status' => Article::IMAGE_STATUS_FAILED,
                'image_error' => $exception->getMessage(),
            ]);

            report($exception);

            // عمداً throw نمی‌کنیم: شکست تصویر نباید کل مقاله را fail کند.
        }
    }

    protected function remainingSeconds(float $startedAt): int
    {
        return (int) floor(
            $this->timeout - self::SAFETY_MARGIN_SECONDS - (microtime(true) - $startedAt)
        );
    }

    protected function saveGallery(array $gallery): void
    {
        $this->article->update(['image_gallery' => array_values($gallery)]);
    }

    /**
     * همه‌ی آیتم‌های هنوز pending را به وضعیت داده‌شده تغییر می‌دهد.
     */
    protected function markPending(array &$gallery, string $status, string $reason): void
    {
        foreach ($gallery as $i => $item) {
            if (($item['status'] ?? null) === self::GALLERY_PENDING) {
                $gallery[$i]['status'] = $status;
                $gallery[$i]['error'] = $reason;
            }
        }
    }

    /**
     * برای شکست‌هایی که از catch داخلی رد می‌شوند (kill شدن ورکر، MaxAttemptsExceeded).
     * فقط اگر هنوز در حالت میانی است می‌نویسیم تا 'generated' را خراب نکنیم.
     * promptهای گالری حفظ می‌شوند؛ آیتم‌های pending به failed تبدیل می‌شوند.
     */
    public function failed(?Throwable $exception): void
    {
        $article = Article::query()
            ->whereKey($this->article->id)
            ->whereIn('image_status', [Article::IMAGE_STATUS_PENDING, Article::IMAGE_STATUS_GENERATING])
            ->first();

        if (! $article) {
            return;
        }

        $message = $exception?->getMessage() ?? 'Unknown error while generating the image';

        $raw = $article->image_gallery;
        $gallery = is_string($raw) ? (json_decode($raw, true) ?: []) : ($raw ?? []);

        $gallery = array_values(array_map(
            fn ($item) => ($item['status'] ?? null) === self::GALLERY_PENDING
                ? array_merge($item, ['status' => self::GALLERY_FAILED, 'error' => $message])
                : $item,
            $gallery
        ));

        $article->update([
            'image_status' => Article::IMAGE_STATUS_FAILED,
            'image_error' => $message,
            'image_gallery' => $gallery,
        ]);
    }
}
