<?php
// app/Services/AI/SiteLogoResolver.php

namespace App\Services\AI;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * پیدا کردن و دانلودِ لوگوی یک سایت، فقط از روی دامنه‌اش.
 *
 * اولویتِ منابع (از تازه‌ترین به کهنه‌ترین):
 *   1. site.webmanifest / manifest.json  -> آرایه‌ی icons (بزرگ‌ترین سایز)
 *   2. <link rel="icon" | "apple-touch-icon"> در HTML صفحه‌ی اصلی
 *   3. /favicon.ico مستقیم
 *   4. [آخرین راه] Clearbit / Google Favicons / Unavatar
 *
 * === جدید: استخراجِ رنگِ برند ===
 * علاوه بر دانلودِ لوگو، رنگ‌های اصلیِ آن هم با LogoColorExtractor
 * استخراج و کنارِ URL کش می‌شود (همان ۳۰ روز). این برایِ حلِ این مشکل
 * است که مدلِ AI موقعِ ساختنِ بنر فقط یک URL از لوگو می‌بیند و رنگِ
 * واقعیِ برند را نمی‌شناسد/حدس می‌زند. با resolvePalette() می‌توانید
 * رنگ‌های هگزِ واقعی را به پرامپت بدهید.
 */
class SiteLogoResolver
{
    public function __construct(protected LogoColorExtractor $colorExtractor = new LogoColorExtractor()) {}

    /**
     * @return string|null آدرسِ عمومیِ لوگوی دانلودشده، یا null اگر هیچ منبعی جواب نداد
     */
    public function resolve(Site $site, bool $forceRefresh = false): ?string
    {
        return $this->resolveData($site, $forceRefresh)['url'] ?? null;
    }

    /**
     * رنگ‌های اصلیِ لوگو (هگز)، مثلاً ['#2E9E8C', '#1B6F63'].
     * اگر لوگو پیدا نشده باشد یا تک‌رنگ/بی‌رنگ باشد، آرایه‌ی خالی برمی‌گردد
     * — در این حالت کدِ صدازننده باید اجازه بدهد AI خودش پالت انتخاب کند.
     *
     * @return string[]
     */
    public function resolvePalette(Site $site, bool $forceRefresh = false): array
    {
        return $this->resolveData($site, $forceRefresh)['palette'] ?? [];
    }

    /**
     * @return array{url: ?string, palette: string[]}
     */
    protected function resolveData(Site $site, bool $forceRefresh): array
    {
        if (empty($site->domain)) {
            return ['url' => null, 'palette' => []];
        }

        $cacheKey = "site_logo_data:{$site->id}";

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($site) {
            return $this->downloadLogo($site);
        }) ?? ['url' => null, 'palette' => []];
    }

    /**
     * @return array{url: ?string, palette: string[]}
     */
    protected function downloadLogo(Site $site): array
    {
        $domain = $this->normalizeDomain($site->domain);

        if ($domain === '') {
            return ['url' => null, 'palette' => []];
        }

        foreach ($this->discoverDirectCandidates($domain) as $url) {
            if ($saved = $this->tryDownload($site, $domain, $url, 'direct')) {
                return $saved;
            }
        }

        $providers = [
            "https://logo.clearbit.com/{$domain}?size=256",
            "https://www.google.com/s2/favicons?sz=256&domain={$domain}",
            "https://unavatar.io/{$domain}",
        ];

        foreach ($providers as $url) {
            if ($saved = $this->tryDownload($site, $domain, $url, 'provider')) {
                return $saved;
            }
        }

        Log::warning('ai.site_logo_resolver.not_found', [
            'site_id' => $site->id,
            'domain' => $domain,
        ]);

        return ['url' => null, 'palette' => []];
    }

    /**
     * @return string[]
     */
    protected function discoverDirectCandidates(string $domain): array
    {
        $html = null;

        try {
            $html = Http::timeout(6)->get("https://{$domain}")->body();
        } catch (Throwable $e) {
            // اگر صفحه‌ی اصلی جواب نداد، همچنان favicon.ico را امتحان می‌کنیم
        }

        $candidates = [];

        if ($html) {
            if (preg_match('#<link[^>]+rel=["\']manifest["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m)) {
                $manifestUrl = $this->toAbsoluteUrl($m[1], $domain);
                foreach ($this->iconsFromManifest($manifestUrl, $domain) as $iconUrl) {
                    $candidates[] = $iconUrl;
                }
            }

            if (preg_match_all(
                '#<link[^>]+rel=["\'](?:apple-touch-icon(?:-precomposed)?|shortcut icon|icon)["\'][^>]*href=["\']([^"\']+)["\']#i',
                $html,
                $matches
            )) {
                $pairs = array_combine($matches[0], $matches[1]);
                uksort($pairs, fn ($a, $b) => str_contains($a, 'apple-touch-icon') ? -1 : 1);

                foreach ($pairs as $href) {
                    $candidates[] = $this->toAbsoluteUrl($href, $domain);
                }
            }
        }

        $candidates[] = "https://{$domain}/favicon.ico";

        return array_values(array_unique($candidates));
    }

    /**
     * @return string[]
     */
    protected function iconsFromManifest(string $manifestUrl, string $domain): array
    {
        try {
            $json = Http::timeout(6)->get($manifestUrl)->json();
            $icons = $json['icons'] ?? [];

            if (empty($icons)) {
                return [];
            }

            usort($icons, function ($a, $b) {
                $sizeA = (int) explode('x', $a['sizes'] ?? '0x0')[0];
                $sizeB = (int) explode('x', $b['sizes'] ?? '0x0')[0];
                return $sizeB <=> $sizeA;
            });

            return array_map(fn ($icon) => $this->toAbsoluteUrl($icon['src'], $domain), $icons);
        } catch (Throwable $e) {
            return [];
        }
    }

    protected function toAbsoluteUrl(string $href, string $domain): string
    {
        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $href;
        }
        if (Str::startsWith($href, '//')) {
            return "https:{$href}";
        }
        if (Str::startsWith($href, '/')) {
            return "https://{$domain}{$href}";
        }
        return "https://{$domain}/{$href}";
    }

    /**
     * یک URL کاندید را دانلود و در صورت معتبر بودن ذخیره می‌کند، و رنگِ
     * برند را از روی همان بایت‌ها (قبل از رفتنِ روی دیسک) استخراج می‌کند.
     *
     * @return array{url: string, palette: string[]}|null
     */
    protected function tryDownload(Site $site, string $domain, string $url, string $sourceType): ?array
    {
        try {
            $response = Http::timeout(10)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $contentType = (string) $response->header('Content-Type');

            if (! Str::startsWith($contentType, 'image/')) {
                return null;
            }

            $bytes = $response->body();

            if (strlen($bytes) < 500) {
                return null;
            }

            $extension = $this->extensionFromContentType($contentType);
            $hash = substr(md5($bytes), 0, 8);
            $path = "banners/logos/site_{$site->id}_{$hash}.{$extension}";

            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->put($path, $bytes);
                $this->cleanupOldLogos($site->id, $path);
            }

            $palette = $this->colorExtractor->extract($bytes, $contentType);

            Log::info('ai.site_logo_resolver.downloaded', [
                'site_id' => $site->id,
                'domain' => $domain,
                'source' => $url,
                'source_type' => $sourceType,
                'bytes' => strlen($bytes),
                'hash' => $hash,
                'palette' => $palette,
            ]);

            return [
                'url' => Storage::disk('public')->url($path),
                'palette' => $palette,
            ];
        } catch (Throwable $e) {
            Log::warning('ai.site_logo_resolver.candidate_failed', [
                'site_id' => $site->id,
                'domain' => $domain,
                'source' => $url,
                'source_type' => $sourceType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function cleanupOldLogos(int $siteId, string $keepPath): void
    {
        $disk = Storage::disk('public');
        $files = $disk->files('banners/logos');

        foreach ($files as $file) {
            if ($file !== $keepPath && Str::startsWith(basename($file), "site_{$siteId}_")) {
                $disk->delete($file);
            }
        }
    }

    protected function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = preg_replace('#^www\.#i', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];
        $domain = explode('?', $domain)[0];
        return trim($domain);
    }

    protected function extensionFromContentType(string $contentType): string
    {
        return match (true) {
            Str::contains($contentType, 'png') => 'png',
            Str::contains($contentType, 'jpeg'), Str::contains($contentType, 'jpg') => 'jpg',
            Str::contains($contentType, 'svg') => 'svg',
            Str::contains($contentType, 'webp') => 'webp',
            Str::contains($contentType, 'gif') => 'gif',
            default => 'png',
        };
    }
}
