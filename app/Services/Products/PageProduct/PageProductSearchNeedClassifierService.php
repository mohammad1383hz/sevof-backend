<?php

namespace App\Services\Products\PageProduct;

use App\Models\User;
use App\Services\AI\Concerns\UsesGapgptApiKey;
use App\Services\TokenUsageService;

/**
 * مرحله ۲: تشخیص true/false با مدل ارزان، به‌صورت دسته‌ای.
 * ورودی فقط: نام + برند + دسته‌بندی (+ مدل سازنده اگر موجود باشد).
 */
class PageProductSearchNeedClassifierService
{
    use UsesGapgptApiKey;

    private const SYSTEM = <<<'TXT'
تو تصمیم می‌گیری برای هر محصول فروشگاه اینترنتی، جست‌وجوی اینترنتی برای یافتن مشخصات فنی دقیق لازم است یا نه.

- true: کالاهایی که مشخصات فنی دقیق و ثابت دارند و برند/مدل مشخص دارند؛ مثل موبایل، لپ‌تاپ، لوازم برقی و دیجیتال، هدفون، ابزار برقی، لوازم خانگی برنددار.
- false: کالاهایی که مشخصاتشان کلی و توصیفی است یا باید از فروشنده بیاید؛ مثل پتو، پارچه، پوشاک، دکور، مبل و صندلی تولیدی، صنایع دستی، لوازم آشپزخانه ساده، محصول بدون برند.
- اسم برند به‌تنهایی کافی نیست؛ نام محصول و دسته‌بندی را هم در نظر بگیر (مثلاً صندلی تولیدی با برند خود فروشگاه: false).
- در تردید، اگر محصول عدد فنی حساس دارد (ظرفیت، توان، رم، باتری) true بگذار.

فقط یک JSON برگردان که کلیدش id هر محصول و مقدارش true یا false است. هیچ متن دیگری ننویس.
TXT;

    public function __construct(protected TokenUsageService $usage)
    {
    }

    /**
     * @param  array<int, array>  $snapshots  id => snapshot
     * @return array<int, bool>  id => needs_search
     */
    public function classify(array $snapshots, User $user): array
    {
        $flags = [];

        foreach (array_chunk($snapshots, (int) config('page_product_seo.classify_chunk', 50), true) as $chunk) {
            $items = [];

            foreach ($chunk as $id => $s) {
                $items[] = array_filter([
                    'id' => $id,
                    'name' => $s['name'],
                    'brand' => $s['brand'],
                    'category' => $s['category'],
                    'model' => $s['model'],
                ], static fn ($v) => $v !== null && $v !== '');
            }

            $model = (string) config('page_product_seo.ai.classifier_model');

            $response = $this->callGapgptChat([
                'model' => $model,
                'max_tokens' => 800,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM],
                    ['role' => 'user', 'content' => json_encode($items, JSON_UNESCAPED_UNICODE)],
                ],
            ], 60);

            if (! $response->successful()) {
                throw new \RuntimeException((string) ($response->json('error.message') ?? 'AI service error.'));
            }

            // هزینه‌ی توکن (دلار → تومان → کریدیت) و کسر از اشتراک
            $this->usage->recordUsageFromResponse(
                $user,
                'page_product_seo_classify',
                (array) $response->json('usage'),
                $model,
                ['optimization_ids' => array_keys($chunk)],
            );

            $out = json_decode((string) $response->json('choices.0.message.content'), true);

            if (! is_array($out)) {
                throw new \RuntimeException('The AI output was not valid JSON.');
            }

            foreach ($chunk as $id => $_) {
                $flags[$id] = filter_var($out[(string) $id] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $flags;
    }
}
