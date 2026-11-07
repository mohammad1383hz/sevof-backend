<?php

namespace App\Jobs\Products;

use App\Enums\ImageStyle;
use App\Models\Product;
use App\Models\ProductArticle;
use App\Models\SiteProduct;
use App\Services\Image\ImageGenerationService;
use App\Services\Image\ImageModelResolver;
use App\Services\Products\ProductArticleWriterService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GenerateProductArticleImageJob implements ShouldQueue
{
    use Queueable;

    // عمداً ۱: retry یعنی تولید و پرداخت دوباره‌ی تصویر.
    public int $tries = 1;

    // prompt (حداکثر ~۳۰ ثانیه) + تصویر (۷۰ ثانیه) + آپلود. retry_after صف باید بزرگ‌تر از این باشد.
    public int $timeout = 180;

    protected const SAFETY_MARGIN_SECONDS = 20;
    protected const UPLOAD_RESERVE_SECONDS = 10;
    protected const PER_IMAGE_MIN_SECONDS = 20;
    protected const PER_IMAGE_SECONDS = 70;

    // حداکثر تعداد محصولی که در prompt تصویر لحاظ می‌شود (بیشتر از این تصویر شلوغ می‌شود)
    protected const MAX_PRODUCTS = 4;

    public function __construct(public ProductArticle $article) {}

    public function handle(
        ProductArticleWriterService $writer,
        ImageGenerationService $imageService,
        SubscriptionService $subscriptionService,
    ): void {
        $startedAt = microtime(true);

        // Claim اتمیک: فقط یک job می‌تواند pending → generating کند.
        $claimed = ProductArticle::query()
            ->whereKey($this->article->id)
            ->where('image_status', ProductArticle::IMAGE_STATUS_PENDING)
            ->update(['image_status' => ProductArticle::IMAGE_STATUS_GENERATING, 'image_error' => null]);

        if ($claimed === 0) {
            Log::info('GenerateProductArticleImageJob: already claimed/finished, skipping', [
                'article_id' => $this->article->id,
            ]);

            return;
        }

        $this->article->refresh();

        $user = $this->article->site->user;

        app()->bind('current_ai_user', fn () => $user);

        if (! $subscriptionService->hasAvailableCredit($user)) {
            $this->article->update([
                'image_status' => ProductArticle::IMAGE_STATUS_FAILED,
                'image_error' => 'Not enough credit to generate the image.',
            ]);

            return;
        }

        $isPaidUser = $subscriptionService->hasActivePaidPlan($user);

        // فقط ۱ تصویر (شاخص) می‌خواهیم؛ count همیشه ۱ است.
        [$modelId] = ImageModelResolver::resolve(
            $this->article->image_model,
            1,
            $isPaidUser,
        );

        $imageStyle = $this->article->image_style instanceof ImageStyle
            ? $this->article->image_style
            : ImageStyle::resolve($this->article->image_style ?: 'product');

        try {
            $products = $this->loadProducts();

            if ($products->isEmpty()) {
                Log::warning('GenerateProductArticleImageJob: no products found, falling back to title only', [
                    'article_id' => $this->article->id,
                ]);
            }

                      $promptData = $writer->generateFeaturedImagePrompt(
                $this->article,
                $products,
                $imageStyle->value,
                $modelId,
                $this->article->image_user_prompt,
                $isPaidUser,
            );

            $prompt = trim($promptData['image_prompt'] ?? '');
            $alt = trim($promptData['alt_text'] ?? '');

            if ($prompt === '') {
                throw new RuntimeException('The featured image prompt came back empty.');
            }

            // prompt قبل از تولید تصویر ذخیره می‌شود تا اگر تصویر fail شد از بین نرود.
            $this->article->update([
                'image_prompt' => $prompt,
                'image_alt' => $alt,
                'image_model' => $modelId,
            ]);

            $remaining = (int) floor(
                $this->timeout - self::SAFETY_MARGIN_SECONDS - (microtime(true) - $startedAt)
            );

            $budget = max(
                self::PER_IMAGE_MIN_SECONDS,
                min(self::PER_IMAGE_SECONDS, $remaining - self::UPLOAD_RESERVE_SECONDS)
            );

            $result = $imageService->generate(
                user: $user,
                modelId: $modelId,
                prompt: $prompt,
                userIsPro: $isPaidUser,
                maxWaitSeconds: $budget,
                fileName: $promptData['file_name'] ?? null,
            );

            $this->article->update([
                'image_path' => $result['path'],
                'image_url' => $result['url'],
                'image_status' => ProductArticle::IMAGE_STATUS_GENERATED,
                'image_error' => null,
            ]);
        } catch (Throwable $e) {
            Log::error('GenerateProductArticleImageJob failed', [
                'article_id' => $this->article->id,
                'model' => $modelId,
                'image_style' => $imageStyle->value,
                'message' => $e->getMessage(),
            ]);

            $this->article->update([
                'image_status' => ProductArticle::IMAGE_STATUS_FAILED,
                'image_error' => 'Featured image generation failed: '.$e->getMessage(),
            ]);

            report($e);

            // عمداً throw نمی‌کنیم: شکست تصویر نباید کل مقاله (یا chain) را fail کند.
        }
    }

    /**
     * محصولات مقاله به همان ترتیبی که در product_ids آمده‌اند (حداکثر MAX_PRODUCTS).
     */
    protected function loadProducts()
    {
        $ids = array_values(array_filter((array) $this->article->product_ids));
        $ids = array_slice($ids, 0, self::MAX_PRODUCTS);

        if ($ids === []) {
            return collect();
        }

        return SiteProduct::query()
            ->where('site_id', $this->article->site_id)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))
            ->values();
    }

    /**
     * برای kill شدن ورکر / MaxAttemptsExceeded. فقط اگر هنوز در حالت میانی است می‌نویسیم.
     */
    public function failed(?Throwable $exception): void
    {
        ProductArticle::query()
            ->whereKey($this->article->id)
            ->whereIn('image_status', [
                ProductArticle::IMAGE_STATUS_PENDING,
                ProductArticle::IMAGE_STATUS_GENERATING,
            ])
            ->update([
                'image_status' => ProductArticle::IMAGE_STATUS_FAILED,
                'image_error' => $exception?->getMessage() ?? 'Unknown error while generating the product featured image',
            ]);
    }
}
