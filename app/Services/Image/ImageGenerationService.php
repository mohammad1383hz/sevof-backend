<?php

namespace App\Services\Image;

use App\Exceptions\ImageModelNotAvailableException;
use App\Exceptions\ImageModelRequiresProException;
use App\Models\User;
use App\Services\CloudflareImageService;
use App\Services\TokenUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ImageGenerationService
{
    // بودجه‌ی زمانیِ پیش‌فرض برای هر تصویر (ثانیه).
    public const DEFAULT_MAX_WAIT_SECONDS = 70;

    // حداکثر تعداد تلاش برای خطاهای موقتی OpenAI (429 معمولی / 5xx).
    protected const MAX_ATTEMPTS = 3;

    protected const RETRY_DELAY_SECONDS = 3;

    public function __construct(
        protected CloudflareImageService $cloudflare,
        protected OpenAiClient $openAi,
        protected KieAiClient $kie,
        protected TokenUsageService $tokenUsageService,
    ) {}

    /**
     * ترتیب کار:
     *  - کاربر رایگان: کلیدهای فعال Kie AI (nano-banana-2-lite) یکی‌یکی امتحان می‌شوند.
     *                   کلیدی که اعتبارش تمام شده (code 402) غیرفعال می‌شود و کلید بعدی امتحان می‌شود.
     *                   اگر هیچ کلید active ای نماند → Cloudflare (flux).
     *  - کاربر پولی: همیشه و فقط OpenAI رسمی (gpt-image-2)، با کلید hardcode داخل OpenAiClient.
     *    نه Kie، نه ShortAPI، نه Cloudflare. اگر شکست خورد exception پرتاب می‌شود.
     *
     * Cloudflare برای کاربر پولی و برای مسیر Kie فقط برای «ذخیره‌ی فایل نهایی» (uploadFromUrl) استفاده می‌شود، نه تولید.
     *
     * $fileName: نام سئو-فرندلی لاتین بدون پسوند (مثل espresso-machine-guide). خالی → img_<uniqid>.
     * تصویر نهایی همیشه فشرده (WebP، حداکثر ۱۲۸۰px) ذخیره می‌شود.
     *
     * @return array{filename:string,path:string,url:string}
     */
    public function generate(
        User $user,
        string $modelId,
        string $prompt,
        bool $userIsPro = false,
        ?int $maxWaitSeconds = null,
        ?string $fileName = null,
    ): array {
        $maxWaitSeconds ??= self::DEFAULT_MAX_WAIT_SECONDS;

        if ($userIsPro) {
            return $this->generateForPaidUser($user, $prompt, $maxWaitSeconds, $fileName);
        }

        return $this->generateForFreeUser($user, $modelId, $prompt, $maxWaitSeconds, $fileName);
    }

    /**
     * کاربر پولی: همیشه gpt-image-2 روی OpenAI.
     */
    protected function generateForPaidUser(User $user, string $prompt, int $maxWaitSeconds, ?string $fileName = null): array
    {
        $modelId = ImageModelResolver::PAID_MODEL;
        $config = config("image_models.{$modelId}", []);

        $deadline = microtime(true) + max(5, $maxWaitSeconds);

        [$url, $usage] = $this->generateWithOpenAi($prompt, $deadline);

        $result = $this->cloudflare->uploadFromUrl($url, $fileName);

        $this->recordUsage($user, $modelId, 'openai', $config, $prompt, $usage);

        return $result;
    }

    /**
     * کاربر رایگان:
     *  ۱) کلیدهای active مربوط به Kie (کمترین active_requests اول). اگر اعتبار کلیدی تمام شده باشد
     *     غیرفعال می‌شود و کلید بعدی امتحان می‌شود.
     *  ۲) اگر هیچ کلید Kie ای نبود یا همه سوخته بودند → Cloudflare.
     */
    protected function generateForFreeUser(User $user, string $modelId, string $prompt, int $maxWaitSeconds, ?string $fileName = null): array
    {
        $deadline = microtime(true) + max(5, $maxWaitSeconds);
        $triedIds = [];

        while ($this->secondsLeft($deadline) > 5) {
            $kieCredit = DB::table('credits_cloudflare')
                ->where('type', 'kieai')
                ->where('status', 'active')
                ->whereNotIn('id', $triedIds)
                ->orderBy('active_requests')
                ->first();

            if (! $kieCredit) {
                break; // هیچ کلید active ای نمانده → Cloudflare
            }

            $triedIds[] = $kieCredit->id;

            try {
                return $this->generateWithKie(
                    $user,
                    $kieCredit->token,
                    $prompt,
                    $this->secondsLeft($deadline),
                    $fileName,
                );
            } catch (\Throwable $e) {
                if (! $this->isKieCreditsExhausted($e->getMessage())) {
                    throw $e; // خطاهای دیگر مثل قبل بالا می‌روند
                }

                DB::table('credits_cloudflare')
                    ->where('id', $kieCredit->id)
                    ->update(['status' => 'inactive', 'updated_at' => now()]);

                Log::warning('KieAi: کلید غیرفعال شد (اعتبار ناکافی)', [
                    'credit_id' => $kieCredit->id,
                ]);

                continue; // برو سراغ کلید بعدی
            }
        }

        // ── Cloudflare ──
        $config = config("image_models.{$modelId}");

        if (! $config) {
            throw new ImageModelNotAvailableException("Unknown model \"{$modelId}\".");
        }

        if (empty($config['provider'])) {
            throw new ImageModelNotAvailableException("The \"{$config['label']}\" model isn't set up yet.");
        }

        if (! empty($config['is_pro']) || $config['provider'] !== 'cloudflare') {
            throw new ImageModelRequiresProException("The \"{$config['label']}\" model is available on Pro only.");
        }

        $result = $this->cloudflare->generate($prompt, [], $fileName);
        $this->recordUsage($user, $modelId, 'cloudflare', $config, $prompt);

        return $result;
    }

    /**
     * کاربر رایگان با یک کلید Kie AI: nano-banana-2-lite، سپس آپلود نتیجه روی Cloudflare.
     * اگر اعتبار کلید تمام شده باشد RuntimeException با مارکر code:402 پرتاب می‌شود.
     */
    protected function generateWithKie(User $user, string $apiKey, string $prompt, int $maxWaitSeconds, ?string $fileName = null): array
    {
        $url = $this->kie->generate($apiKey, $prompt, max(5, $maxWaitSeconds));

        $result = $this->cloudflare->uploadFromUrl($url, $fileName);

        $this->recordUsage($user, KieAiClient::MODEL, 'kieai', [
            'label' => 'Kie AI ('.KieAiClient::MODEL.')',
            'provider' => 'kieai',
        ], $prompt);

        return $result;
    }

    /**
     * تشخیص اینکه خطای Kie به‌خاطر تمام شدن اعتبار کلید است.
     */
    protected function isKieCreditsExhausted(string $message): bool
    {
        return str_contains($message, 'KieAi')
            && (
                str_contains($message, KieAiClient::CREDITS_EXHAUSTED_MARKER)
                || str_contains($message, 'http_status:402')
            );
    }

    /**
     * OpenAI رسمی با یک کلید ثابت. برای خطاهای موقتی (rate limit معمولی، 5xx)
     * چند بار دوباره تلاش می‌کند، به شرطی که از deadline بیرون نزنیم.
     *
     * @return array{0:string,1:?array} [url, usage]
     */
    protected function generateWithOpenAi(string $prompt, float $deadline): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($this->secondsLeft($deadline) <= 5) {
                break;
            }

            try {
                $result = $this->openAi->generate($prompt, $this->secondsLeft($deadline));

                return [$result['url'], $result['usage'] ?? null];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();

                Log::warning('OpenAI: تلاش ناموفق', [
                    'attempt' => $attempt,
                    'error' => $lastError,
                ]);

                if (! $this->isRetryableError($lastError)) {
                    break;
                }

                if ($this->secondsLeft($deadline) <= self::RETRY_DELAY_SECONDS + 10) {
                    break;
                }

                sleep(self::RETRY_DELAY_SECONDS);
            }
        }

        Log::error('OpenAI: تولید تصویر ناموفق بود', [
            'last_error' => $lastError,
        ]);

        throw new RuntimeException('Image generation with OpenAI failed.');
    }

    protected function recordUsage(
        User $user,
        string $modelId,
        string $actualProvider,
        array $config,
        string $prompt,
        ?array $usage = null,
    ): void {
        try {
            $model = $actualProvider === 'openai' ? OpenAiClient::MODEL : $modelId;

            $this->tokenUsageService->recordImageUsage(
                user: $user,
                serviceName: 'image_generation',
                provider: $actualProvider,
                model: $model,
                metadata: [
                    'label' => $config['label'] ?? null,
                    'requested_provider' => $config['provider'] ?? null,
                    'actual_provider' => $actualProvider,
                    'prompt' => $prompt,
                ],
                gapgptUsage: $usage,
            );
        } catch (\Throwable $e) {
            Log::error('image_token_usage_recording_failed', [
                'user_id' => $user->id,
                'model' => $modelId,
                'provider' => $actualProvider,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * خطای موقتی: rate limit معمولی (429) یا خطای سمت سرور (5xx).
     * اگر 429 به‌خاطر تمام شدن اعتبار/quota باشد، تکرار فایده ندارد.
     */
    protected function isRetryableError(string $message): bool
    {
        $message = mb_strtolower($message);

        foreach (['insufficient_quota', 'exceeded your current quota', 'billing_hard_limit'] as $needle) {
            if (str_contains($message, $needle)) {
                return false;
            }
        }

        foreach ([
            'http_status:429', 'http_status:500', 'http_status:502',
            'http_status:503', 'http_status:504',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function secondsLeft(float $deadline): int
    {
        return max(0, (int) floor($deadline - microtime(true)));
    }
}
