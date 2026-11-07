<?php

namespace App\Services;

use App\Models\TokenUsage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class TokenUsageService
{
    /**
     * ضریب سربار (markup) روی هزینه‌ی خام API — طبق تصمیم کسب‌وکار، ۲۰٪
     * (فقط برای مصرف متن/توکن معمولی از طریق calculateCostUsd)
     */
    protected const MARKUP_MULTIPLIER = 1.20;

    /**
     * ضریب سود روی هزینه‌ی خام تصویرِ GapGPT (gpt-image-1-mini) — طبق تصمیم
     * کسب‌وکار ۱۰۰٪ سود، یعنی هزینه‌ی نهایی دو برابر هزینه‌ی خام API می‌شه.
     */
    protected const IMAGE_MARKUP_MULTIPLIER_GAPGPT = 2.0;

    /**
     * ضریب سود روی هزینه‌ی خام تصویرِ OpenAI رسمی (gpt-image-2).
     * فعلاً مثل GapGPT: ۱۰۰٪ سود (هزینه‌ی نهایی = دو برابر هزینه‌ی خام API).
     * هر وقت خواستی سود را عوض کنی فقط همین عدد را عوض کن.
     */
    protected const IMAGE_MARKUP_MULTIPLIER_OPENAI = 2.0;

    /**
     * قیمت رسمی gpt-image-2 (دلار به‌ازای هر ۱ میلیون توکن، تعرفه‌ی Standard).
     * خروجی متن برای این مدل هزینه ندارد؛ همه‌ی توکن‌های خروجی، توکن تصویر حساب می‌شوند.
     * قبل از تغییرات بزرگ، نرخ‌ها را با صفحه‌ی رسمی قیمت OpenAI تطبیق بده.
     */
    protected const OPENAI_IMAGE_TEXT_INPUT_PER_MILLION = 6;

    protected const OPENAI_IMAGE_IMAGE_INPUT_PER_MILLION = 9;

    protected const OPENAI_IMAGE_OUTPUT_PER_MILLION = 30.50;

    /**
     * اگر OpenAI usage برنگرداند (یا صفر بود)، این هزینه‌ی خام flat (دلار) قبل از markup
     * برای هر تصویر حساب می‌شود تا هزینه‌ی صفر ثبت نشود.
     */
    protected const IMAGE_COST_OPENAI_FALLBACK = 0.01;

    /**
     * هزینه‌ی ثابت هر بار تولید عکس — بسته به اینکه کاربر پلن رایگان داره یا
     * پولی، از ست جدا استفاده می‌شه. الان مقادیر پیش‌فرض هر دو یکسانه؛
     * هر وقت خواستی قیمت‌گذاری متفاوت بدی، فقط همین اعداد رو عوض کن.
     */
    protected const IMAGE_COST_CLOUDFLARE_FREE = 0.01;

    protected const IMAGE_COST_SHORTAPI_FREE = 0.03;

    protected const IMAGE_COST_CLOUDFLARE_PAID = 0.01;

    protected const IMAGE_COST_SHORTAPI_PAID = 0.02;

    /**
     * هزینه‌ی تخمینی flat هر عکس وقتی fallback به Kie.ai (nano-banana-2-lite
     * یا gpt-image-2-text-to-image) رخ می‌ده. چون Kie.ai مثل GapGPT توکن
     * دقیق برنمی‌گردونه، مثل بقیه‌ی providerها فلت‌ریت استفاده می‌شه.
     */
    protected const IMAGE_COST_KIEAI_FREE = 0.01;

    protected const IMAGE_COST_KIEAI_PAID = 0.02;

    /**
     * قیمت gpt-image-1-mini (provider = gapgpt) — بر خلاف بقیه‌ی پرووایدرها
     * که هزینه‌شون ثابته، این یکی بر اساس توکن واقعی مصرفی محاسبه می‌شه.
     * قیمت‌ها طبق تعرفه‌ی رسمی gpt-image-1-mini: هر میلیون توکن ورودی/خروجی.
     */
    protected const GAPGPT_IMAGE_INPUT_PER_MILLION = 2.00;

    protected const GAPGPT_IMAGE_OUTPUT_PER_MILLION = 4.00;

    // ==========================================
    // متغیرهای مربوط به محاسبه کریدیت
    // ==========================================
    protected const USD_MULTIPLIER = 3; // ضریبی که روی دلار اعمال می‌شود
    // Value of one credit in USD (after USD_MULTIPLIER). Plan credit limits are set in these units.
    protected const CREDIT_VALUE_USD = 0.01;
    // ==========================================

    public function __construct(
        protected SubscriptionService $subscriptionService,
    ) {
    }

    public function recordUsage(
        User $user,
        string $serviceName,
        int $inputTokens,
        int $outputTokens,
        ?string $model = null,
        ?array $metadata = null,
    ): ?TokenUsage {
        $totalTokens = $inputTokens + $outputTokens;

        $subscription = $this->subscriptionService->getActiveSubscription($user);

        if (! $subscription) {
            Log::warning('token_usage.no_active_subscription', [
                'user_id' => $user->id,
                'service' => $serviceName,
            ]);

            return null;
        }

        $costUsd = $this->calculateCostUsd($model, $inputTokens, $outputTokens);

        $credit = $this->calculateCredit($costUsd);

        $tokenUsage = TokenUsage::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'service_name' => $serviceName,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $costUsd,
            'credit' => $credit,
            'metadata' => $metadata,
        ]);

        $this->subscriptionService->deductTokens($user, $totalTokens);
        $this->subscriptionService->deductCredit($user, $credit); // <--- کسر کریدیت از اشتراک

        Log::info('token_usage.recorded', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'service' => $serviceName,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'cost_usd' => $costUsd,
            'credit' => $credit,
        ]);

        return $tokenUsage;
    }

    public function recordUsageFromResponse(
        User $user,
        string $serviceName,
        array $usageResponse,
        ?string $model = null,
        ?array $metadata = null,
    ): ?TokenUsage {
        $inputTokens = $usageResponse['prompt_tokens'] ?? $usageResponse['input_tokens'] ?? 0;
        $outputTokens = $usageResponse['completion_tokens'] ?? $usageResponse['output_tokens'] ?? 0;

        return $this->recordUsage(
            user: $user,
            serviceName: $serviceName,
            inputTokens: (int) $inputTokens,
            outputTokens: (int) $outputTokens,
            model: $model,
            metadata: $metadata,
        );
    }

    /**
     * @param  array|null  $gapgptUsage  usage خامِ برگشتی از API تصویر. (اسم پارامتر به‌خاطر
     *                                   سازگاری با فراخوانی‌های قبلی همان مانده.)
     *                                   - provider = 'openai' (gpt-image-2):
     *                                     {"input_tokens": x, "output_tokens": y,
     *                                      "input_tokens_details": {"text_tokens": a, "image_tokens": b}}
     *                                   - provider = 'gapgpt' (gpt-image-1-mini):
     *                                     {"input_tokens": x, "output_tokens": y}
     *                                   بقیه‌ی providerها هزینه‌ی flat دارند و usage نمی‌خواهند.
     */
    public function recordImageUsage(
        User $user,
        string $serviceName,
        string $provider,
        ?string $model = null,
        ?array $metadata = null,
        ?array $gapgptUsage = null,
    ): ?TokenUsage {
        $subscription = $this->subscriptionService->getActiveSubscription($user);

        if (! $subscription) {
            Log::warning('token_usage.no_active_subscription', [
                'user_id' => $user->id,
                'service' => $serviceName,
            ]);

            return null;
        }

        // مهم: هزینه‌ی تصویر بر اساس نوع پلن کاربر فرق می‌کنه (رایگان vs پولی).
        // برای بقیه‌ی سرویس‌ها (متن/توکن) این تفاوت اعمال نمی‌شه، فقط برای عکس.
        $isFreePlan = (bool) $subscription->plan->is_free;

        $inputTokens = 0;
        $outputTokens = 0;

        if ($provider === 'openai') {
            [$costUsd, $inputTokens, $outputTokens] = $this->calculateOpenAiImageCostUsd($gapgptUsage);

            // usage خام را برای بررسی و اشکال‌زدایی کنار رکورد نگه می‌داریم.
            $metadata = array_merge($metadata ?? [], ['provider_usage' => $gapgptUsage]);
        } elseif ($provider === 'gapgpt') {
            $inputTokens = (int) ($gapgptUsage['input_tokens'] ?? 0);
            $outputTokens = (int) ($gapgptUsage['output_tokens'] ?? 0);

            $costUsd = $this->calculateGapgptImageCostUsd($inputTokens, $outputTokens);
        } else {
            $costUsd = $this->calculateImageCostUsd($provider, $isFreePlan);
        }

        $credit = $this->calculateCredit($costUsd);

        $tokenUsage = TokenUsage::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'service_name' => $serviceName,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'cost_usd' => $costUsd,
            'credit' => $credit,
            'metadata' => $metadata,
        ]);

        $this->subscriptionService->deductCredit($user, $credit); // <--- کسر کریدیت از اشتراک

        Log::info('token_usage.image_recorded', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'service' => $serviceName,
            'model' => $model,
            'provider' => $provider,
            'is_free_plan' => $isFreePlan,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $costUsd,
            'credit' => $credit,
        ]);

        return $tokenUsage;
    }

    /**
     * هزینه‌ی خام (دلاری) تولید یک عکس رو بر اساس provider و اینکه کاربر
     * پلن رایگان داره یا پولی برمی‌گردونه. این تفاوت فقط برای تصاویره؛
     * محاسبه‌ی هزینه‌ی متن/توکن (calculateCostUsd) کاملاً مستقل و بدون تغییره.
     */
    protected function calculateImageCostUsd(string $provider, bool $isFreePlan): float
    {
        $pricing = $isFreePlan
            ? [
                'cloudflare' => self::IMAGE_COST_CLOUDFLARE_FREE,
                'shortapi' => self::IMAGE_COST_SHORTAPI_FREE,
                'kieai' => self::IMAGE_COST_KIEAI_FREE,
            ]
            : [
                'cloudflare' => self::IMAGE_COST_CLOUDFLARE_PAID,
                'shortapi' => self::IMAGE_COST_SHORTAPI_PAID,
                'kieai' => self::IMAGE_COST_KIEAI_PAID,
            ];

        if (! array_key_exists($provider, $pricing)) {
            Log::warning('token_usage.image_cost_provider_unknown', [
                'provider' => $provider,
                'is_free_plan' => $isFreePlan,
            ]);

            return 0.0;
        }

        return $pricing[$provider];
    }

    /**
     * هزینه‌ی واقعیِ تصویرِ تولیدشده با OpenAI رسمی (gpt-image-2) بر اساس توکن واقعی usage،
     * به‌علاوه‌ی سود (IMAGE_MARKUP_MULTIPLIER_OPENAI).
     *
     * ورودی متن و ورودی تصویر جدا قیمت دارند. اگر input_tokens_details نبود، کل ورودی
     * به‌عنوان متن حساب می‌شود (prompt متنی). توکن‌های cache‌شده تخفیف نمی‌گیرند
     * (محافظه‌کارانه؛ هزینه کمی بیشتر برآورد می‌شود، نه کمتر).
     *
     * اگر usage نبود یا صفر بود، هزینه‌ی flat جایگزین اعمال می‌شود تا هزینه‌ی صفر ثبت نشود.
     *
     * @return array{0:float,1:int,2:int} [costUsd, inputTokens, outputTokens]
     */
    protected function calculateOpenAiImageCostUsd(?array $usage): array
    {
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        if ($inputTokens === 0 && $outputTokens === 0) {
            Log::warning('token_usage.openai_image_usage_missing', [
                'usage' => $usage,
            ]);

            return [
                round(self::IMAGE_COST_OPENAI_FALLBACK * self::IMAGE_MARKUP_MULTIPLIER_OPENAI, 10),
                0,
                0,
            ];
        }

        $details = $usage['input_tokens_details'] ?? [];

        $imageInputTokens = (int) ($details['image_tokens'] ?? 0);
        $textInputTokens = isset($details['text_tokens'])
            ? (int) $details['text_tokens']
            : max(0, $inputTokens - $imageInputTokens);

        $rawCost =
            ($textInputTokens / 1_000_000) * self::OPENAI_IMAGE_TEXT_INPUT_PER_MILLION
            + ($imageInputTokens / 1_000_000) * self::OPENAI_IMAGE_IMAGE_INPUT_PER_MILLION
            + ($outputTokens / 1_000_000) * self::OPENAI_IMAGE_OUTPUT_PER_MILLION;

        return [
            round($rawCost * self::IMAGE_MARKUP_MULTIPLIER_OPENAI, 10),
            $inputTokens,
            $outputTokens,
        ];
    }

    /**
     * هزینه‌ی واقعیِ تصویرِ تولیدشده با GapGPT (gpt-image-1-mini) رو بر اساس
     * توکن واقعی ورودی/خروجی محاسبه می‌کنه و ۱۰۰٪ سود (ضرب در ۲) روش اعمال می‌کنه.
     * یعنی: هزینه‌ی خام API × ۲ = هزینه‌ای که از کاربر کسر می‌شه.
     */
    protected function calculateGapgptImageCostUsd(int $inputTokens, int $outputTokens): float
    {
        $inputCost = ($inputTokens / 1_000_000) * self::GAPGPT_IMAGE_INPUT_PER_MILLION;
        $outputCost = ($outputTokens / 1_000_000) * self::GAPGPT_IMAGE_OUTPUT_PER_MILLION;

        $rawCost = $inputCost + $outputCost;

        return round($rawCost * self::IMAGE_MARKUP_MULTIPLIER_GAPGPT, 10);
    }

    /**
     * متد جدید: محاسبه کریدیت بر اساس هزینه دلاری
     */
    protected function calculateCredit(?float $costUsd): float
    {
        if (! $costUsd) {
            return 0.0;
        }

        $adjustedUsdCost = $costUsd * self::USD_MULTIPLIER;

        $credit = $adjustedUsdCost / (float) config('seobot.credit_value_usd', self::CREDIT_VALUE_USD);

        // گرد کردن به ۴ رقم اعشار برای جلوگیری از اعداد خیلی طولانی در دیتابیس
        return round($credit, 4);
    }

    protected const MODEL_PRICING = [
        'gpt-4.1-mini' => [
            'input_per_million' => 0.40,
            'output_per_million' => 1.60,
        ],
        'gpt-5-mini' => [
            'input_per_million' => 0.25,
            'output_per_million' => 2.00,
        ],
        'gpt-4o' => [
            'input_per_million' => 2.75,
            'output_per_million' => 11.00,
        ],
        'gpt-5.4-nano' => [
            'input_per_million' => 0.20,
            'output_per_million' => 1.25,
        ],
        'gpt-5-nano' => [
            'input_per_million' => 0.050,
            'output_per_million' => 0.40,
        ],
        'gpt-5.4-mini' => [
            'input_per_million' => 0.75,
            'output_per_million' => 4.50,
        ],
        'gpt-5.6-luna' => [
            'input_per_million' => 1.00,
            'output_per_million' => 6.00,
        ],
        'gpt-4o-mini' => [
            'input_per_million' => 0.15,
            'output_per_million' => 0.60,
        ],
    ];

    protected function calculateCostUsd(?string $model, int $inputTokens, int $outputTokens): ?float
    {
        if (! $model) {
            return null;
        }

        $pricing = self::MODEL_PRICING[$model] ?? null;

        if (! $pricing) {
            Log::warning('token_usage.pricing_not_found', [
                'model' => $model,
            ]);

            return null;
        }

        $inputCost = ($inputTokens / 1_000_000) * (float) ($pricing['input_per_million'] ?? 0);
        $outputCost = ($outputTokens / 1_000_000) * (float) ($pricing['output_per_million'] ?? 0);

        $rawCost = $inputCost + $outputCost;

        return round($rawCost * self::MARKUP_MULTIPLIER, 10);
    }

    /** تخمین کریدیت یک فراخوانی متنی با همان فرمول هزینه‌ی واقعی؛ برای نشان دادن هزینه قبل از شروع کار. */
    public function estimateCredit(string $model, int $inputTokens, int $outputTokens): float
    {
        return $this->calculateCredit($this->calculateCostUsd($model, $inputTokens, $outputTokens));
    }

    public function getUserUsageHistory(User $user, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return TokenUsage::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getUserUsageTotal(User $user, ?string $serviceName = null): int
    {
        $query = TokenUsage::where('user_id', $user->id);

        if ($serviceName) {
            $query->where('service_name', $serviceName);
        }

        return (int) $query->sum('total_tokens');
    }

    public function getUserCostTotal(User $user, ?string $serviceName = null): float
    {
        $query = TokenUsage::where('user_id', $user->id);

        if ($serviceName) {
            $query->where('service_name', $serviceName);
        }

        return (float) $query->sum('cost_usd');
    }

    /**
     * متد جدید (اختیاری): دریافت مجموع کریدیت‌های مصرف شده توسط کاربر
     */
    public function getUserCreditTotal(User $user, ?string $serviceName = null): float
    {
        $query = TokenUsage::where('user_id', $user->id);

        if ($serviceName) {
            $query->where('service_name', $serviceName);
        }

        return (float) $query->sum('credit');
    }
}
