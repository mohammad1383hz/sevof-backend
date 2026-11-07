<?php

namespace App\Services;

use App\Models\CreditCloudflare;
use App\Services\Image\ImageFileName;
use App\Services\Image\ImageOptimizer;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CloudflareImageService
{
    // بعد از محدود شدن یه اکانت، این‌قدر ساعت صبر می‌کنیم تا دوباره تستش کنیم
    protected const COOLDOWN_HOURS = 24;

    // پسوندهای مجاز برای فایل ذخیره‌شده (هر چیز دیگر به jpg تبدیل می‌شود).
    protected const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    /**
     * @param  string|null  $fileName  نام سئو-فرندلی بدون پسوند (مثل coffee-maker-guide)
     */
    public function generate(string $prompt, array $excludedCreditIds = [], ?string $fileName = null): array
    {
        $credit = $this->getActiveCredit($excludedCreditIds);

        if (! $credit) {
            throw new Exception('All Cloudflare accounts are rate-limited right now. Please try again later.');
        }

        $url = "https://api.cloudflare.com/client/v4/accounts/{$credit->account_id}/ai/run/@cf/black-forest-labs/flux-2-klein-9b";

        $start = microtime(true);

        Log::info('Cloudflare Image Generation Started', [
            'url' => $url,
            'account_id' => $credit->account_id,
            'prompt' => $prompt,
        ]);

        try {
            $response = Http::asMultipart()
                ->timeout(180)
                ->withToken($credit->token)
                ->post($url, [
                    ['name' => 'prompt', 'contents' => $prompt],
                    ['name' => 'steps', 'contents' => '25'],
                    ['name' => 'width', 'contents' => '1280'],
                    ['name' => 'height', 'contents' => '720'],
                ]);

            Log::info('Cloudflare Response', [
                'account_id' => $credit->account_id,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $this->sanitizeForLog($response->body()),
                'duration_ms' => round((microtime(true) - $start) * 1000),
            ]);

            if ($response->failed()) {
                if ($this->isRateLimitError($response)) {
                    Log::warning('Cloudflare Account Rate Limited - Switching', [
                        'account_id' => $credit->account_id,
                    ]);

                    $credit->update(['status' => 'inactive']);

                    return $this->generate($prompt, [...$excludedCreditIds, $credit->id], $fileName);
                }

                Log::error('Cloudflare Request Failed', [
                    'account_id' => $credit->account_id,
                    'status' => $response->status(),
                    'body' => $this->sanitizeForLog($response->body()),
                ]);

                throw new Exception($response->body());
            }

            $json = $response->json();

            if (! isset($json['result']['image'])) {
                Log::error('Image Not Found', ['response' => $this->sanitizeForLog($json)]);

                throw new Exception('Image not found in response.');
            }

            $image = base64_decode($json['result']['image']);

            $result = $this->store($image, 'jpg', $fileName);

            Log::info('Image Saved', [
                'filename' => $result['filename'],
                'account_id' => $credit->account_id,
                'url' => $result['url'],
            ]);

            return $result;

        } catch (\Throwable $e) {
            Log::error('Cloudflare Image Generation Exception', [
                'account_id' => $credit->account_id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    /**
     * یه اکانت اکتیو با کمترین updated_at (یعنی کمتر تازه استفاده‌شده) برمی‌گردونه.
     * اگه هیچ‌کدوم اکتیو نبود، قدیمی‌ترین inactive که بیشتر از ۲۴ ساعته
     * غیرفعاله رو دوباره فعال می‌کنه.
     */
    protected function getActiveCredit(array $excludedIds = []): ?CreditCloudflare
    {
        if (! CreditCloudflare::where('type', 'cloudflare')->where('status', 'active')->exists()) {
            $oldest = CreditCloudflare::where('type', 'cloudflare')
                ->where('status', 'inactive')
                ->where('updated_at', '<=', now()->subHours(self::COOLDOWN_HOURS))
                ->orderBy('updated_at')
                ->first();

            $oldest?->update(['status' => 'active']);
        }

        return CreditCloudflare::where('type', 'cloudflare')
            ->where('status', 'active')
            ->whereNotIn('id', $excludedIds)
            ->orderBy('updated_at')
            ->first();
    }

    /**
     * تشخیص خطای rate limit / quota از پاسخ Cloudflare.
     */
    protected function isRateLimitError($response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        $errors = $response->json('errors') ?? [];

        foreach ($errors as $error) {
            $message = strtolower($error['message'] ?? '');

            if (str_contains($message, 'limit') || str_contains($message, 'quota')) {
                return true;
            }
        }

        return false;
    }

    /**
     * تصویر را از یک URL معمولی (http/https) یا یک data URI (data:image/png;base64,...)
     * می‌گیرد و در دیسک public ذخیره می‌کند.
     *
     * @param  string|null  $fileName  نام سئو-فرندلی بدون پسوند (مثل coffee-maker-guide)
     * @return array{filename:string,path:string,url:string}
     */
    public function uploadFromUrl(string $remoteUrl, ?string $fileName = null): array
    {
        $isDataUri = $this->isDataUri($remoteUrl);

        Log::info('Cloudflare Upload From URL Started', [
            'remote_url' => $this->describeUrlForLog($remoteUrl),
        ]);

        try {
            if ($isDataUri) {
                [$image, $contentType] = $this->decodeDataUri($remoteUrl);
            } else {
                $response = Http::timeout(60)->get($remoteUrl);

                if ($response->failed()) {
                    Log::error('Download From Remote URL Failed', [
                        'url' => $remoteUrl,
                        'status' => $response->status(),
                    ]);

                    throw new Exception('Downloading the image from the model server failed.');
                }

                $image = $response->body();
                $contentType = $response->header('Content-Type');
            }

            $result = $this->store($image, $this->guessExtension($remoteUrl, $contentType), $fileName);

            Log::info('Remote Image Saved', [
                'filename' => $result['filename'],
                'source' => $isDataUri ? 'data-uri' : 'url',
                'original_bytes' => strlen($image),
                'path' => $result['path'],
                'url' => $result['url'],
            ]);

            return $result;

        } catch (\Throwable $e) {
            Log::error('Cloudflare Upload From URL Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    /**
     * فشرده‌سازی (WebP، حداکثر ۱۲۸۰px) + ذخیره با نام سئو-فرندلی و یکتا در images/.
     * فایل اصلیِ سنگین هیچ‌وقت روی دیسک نمی‌ماند؛ پس همه‌ی مسیرهای انتشار (وردپرس/میکسین) فایل سبک را می‌خوانند.
     *
     * @return array{filename:string,path:string,url:string}
     */
    protected function store(string $binary, string $extension, ?string $fileName): array
    {
        [$binary, $extension] = app(ImageOptimizer::class)->optimize($binary, $extension);

        $disk = Storage::disk('public');
        $directory = 'images';

        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }

        $base = ImageFileName::slugify($fileName) ?? uniqid('img_');
        $filename = $this->claimUniqueFile($disk->path($directory), $base, $extension);

        file_put_contents($disk->path("{$directory}/{$filename}"), $binary);

        return [
            'filename' => $filename,
            'path' => $disk->path("{$directory}/{$filename}"),
            'url' => Storage::url("{$directory}/{$filename}"),
        ];
    }

    /**
     * نام یکتا را اتمیک رزرو می‌کند: coffee-maker.webp، اگر بود coffee-maker-2.webp و ...
     * fopen با حالت 'x' فقط وقتی فایل وجود ندارد موفق می‌شود؛ پس دو worker موازی با نام یکسان
     * هیچ‌وقت فایل همدیگر را overwrite نمی‌کنند (exists() + put() این تضمین را نداشت).
     */
    protected function claimUniqueFile(string $directory, string $base, string $extension): string
    {
        for ($n = 1; $n <= 500; $n++) {
            $filename = $n === 1 ? "{$base}.{$extension}" : "{$base}-{$n}.{$extension}";
            $handle = @fopen($directory.DIRECTORY_SEPARATOR.$filename, 'x');

            if ($handle !== false) {
                fclose($handle);

                return $filename;
            }
        }

        // عملاً پیش نمی‌آید؛ برای اطمینان یک پسوند تصادفی.
        return "{$base}-".substr(uniqid(), -6).".{$extension}";
    }

    protected function isDataUri(string $url): bool
    {
        return str_starts_with(strtolower(ltrim($url)), 'data:');
    }

    /**
     * data URI را decode می‌کند.
     * هم حالت base64 (data:image/png;base64,XXXX) و هم حالت URL-encoded را پشتیبانی می‌کند.
     *
     * @return array{0:string,1:string} [binary, mimeType]
     */
    protected function decodeDataUri(string $dataUri): array
    {
        $dataUri = ltrim($dataUri);
        $comma = strpos($dataUri, ',');

        if ($comma === false) {
            throw new Exception('Invalid data URI (no comma separator).');
        }

        $header = strtolower(substr($dataUri, 5, $comma - 5)); // مثل: image/png;base64
        $payload = substr($dataUri, $comma + 1);

        $mime = trim(explode(';', $header)[0]) ?: 'text/plain';

        if (! str_starts_with($mime, 'image/')) {
            throw new Exception("The data URI is not an image (mime: {$mime}).");
        }

        if (str_contains($header, ';base64')) {
            $binary = base64_decode(preg_replace('/\s+/', '', $payload), true);
        } else {
            $binary = rawurldecode($payload);
        }

        if ($binary === false || $binary === '') {
            throw new Exception('The data URI content could not be decoded or was empty.');
        }

        return [$binary, $mime];
    }

    private function guessExtension(string $url, ?string $contentType): string
    {
        $map = [
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
        ];

        if ($contentType) {
            // ممکن است Content-Type مثل "image/png; charset=binary" باشد.
            $mime = strtolower(trim(explode(';', $contentType)[0]));

            if (isset($map[$mime])) {
                return $map[$mime];
            }
        }

        // data URI مسیر ندارد؛ فقط برای URL معمولی از پسوند مسیر استفاده می‌کنیم.
        if (! $this->isDataUri($url)) {
            $path = parse_url($url, PHP_URL_PATH);
            $pathExt = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

            if (in_array($pathExt, self::ALLOWED_EXTENSIONS, true)) {
                return $pathExt === 'jpeg' ? 'jpg' : $pathExt;
            }
        }

        return 'jpg';
    }

    /**
     * برای لاگ: data URI را به یک توضیح کوتاه تبدیل می‌کند تا base64 چند مگابایتی وارد لاگ نشود.
     */
    protected function describeUrlForLog(string $url): string
    {
        if ($this->isDataUri($url)) {
            $comma = strpos($url, ',');
            $header = $comma === false ? substr($url, 0, 40) : substr($url, 0, $comma);

            return "data-uri [{$header}] ({$this->formatLength($url)})";
        }

        return $url;
    }

    private function formatLength(string $value): string
    {
        return number_format(strlen($value)).' chars';
    }

    /**
     * قبل از لاگ کردن، هر مقدار رشته‌ای طولانی (مثل base64 عکس) رو
     * به ۵۰ کاراکتر اول کوتاه می‌کنه تا لاگ‌ها حجیم و شلوغ نشن.
     * روی رشته‌ی JSON خام و روی آرایه‌های تودرتو هم کار می‌کنه.
     */
    protected function sanitizeForLog(mixed $data, int $maxLength = 50): mixed
    {
        if (is_array($data)) {
            return array_map(fn ($item) => $this->sanitizeForLog($item, $maxLength), $data);
        }

        if (is_string($data)) {
            // اگه رشته یه JSON معتبر بود (مثل $response->body())، اول decode می‌کنیم
            // تا فیلدهای داخلیش (مثل result.image) هم کوتاه بشن، نه کل رشته یهو قطع بشه بی‌فایده
            $decoded = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return json_encode($this->sanitizeForLog($decoded, $maxLength));
            }

            if (strlen($data) > $maxLength) {
                return substr($data, 0, $maxLength).'... [truncated, original length: '.strlen($data).']';
            }
        }

        return $data;
    }
}
