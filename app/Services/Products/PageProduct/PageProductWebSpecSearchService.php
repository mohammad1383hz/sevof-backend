<?php

namespace App\Services\Products\PageProduct;

use App\Services\SerperDevApiClient;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * مرحله ۴ (مسیر true): جست‌وجوی محدود و استخراج مشخصات با ابزار معمول (بدون AI).
 * فقط صفحه‌ای که با نام/مدل محصول تطبیق داشته باشد پذیرفته می‌شود.
 */
class PageProductWebSpecSearchService
{
    private const MAX_SPECS = 15;

    /** مدل کوتاه‌تر از این تعداد کاراکتر برای تطبیق صفحه قابل‌اتکا نیست. */
    private const MIN_MODEL_LENGTH = 4;

    /** صفحه‌های لیست/جست‌وجو مشخصات چند مدل را قاطی می‌کنند و پذیرفته نمی‌شوند. */
    private const LISTING_HINTS = ['/search', '/browse/', '/category', '/cat/', '?q=', '?query='];

    private const DIMENSION_LABELS = [
        'weight' => 'وزن', 'width' => 'عرض', 'height' => 'ارتفاع', 'depth' => 'عمق',
    ];

    private const SIMPLE_LABELS = [
        'model' => 'مدل', 'mpn' => 'شناسه سازنده', 'color' => 'رنگ', 'material' => 'جنس',
    ];

    /**
     * @return array{verified: bool, specs: list<array{name:string,value:string,source:string}>, sources: list<string>}
     */
    public function find(array $snapshot): array
    {
        $empty = ['verified' => false, 'specs' => [], 'sources' => []];
        $cfg = (array) config('page_product_seo.search');

        // سوئیچ اصلی: تا وقتی سرچ غیرفعال است هیچ درخواستی به Serper زده نمی‌شود
        if (! ($cfg['enabled'] ?? false)) {
            return $empty;
        }

        // بدون برند و مدل واقعی، هویت محصول قابل‌تأیید نیست؛ سرچ نمی‌کنیم و به فروشنده ارجاع می‌شود
        if (empty($snapshot['brand']) && empty($snapshot['model'])) {
            return $empty;
        }

        $query = trim(implode(' ', array_filter([$snapshot['brand'], $snapshot['model'], $snapshot['name']])));

        try {
            // چرخش کلید، gl=ir، hl=fa و غیرفعال‌شدن خودکار کلید تمام‌شده داخل خود کلاینت انجام می‌شود
            $results = SerperDevApiClient::fromConfig()->search($query);
        } catch (Throwable $e) {
            // کلید فعالی نیست یا همه‌ی کلیدها تمام شده‌اند
            Log::warning('[PageProductWebSpecSearch] search failed', ['error' => $e->getMessage()]);

            return $empty;
        }

        $links = collect($results)->pluck('link')->filter()
            ->reject(fn ($u) => $this->isListingPage((string) $u))
            ->values()->all();
        $links = array_slice($links, 0, max(1, (int) ($cfg['max_results'] ?? 10)));

        // اولویت با سایت سازنده (دامنه‌ای که نام برند را دارد)
        $brandKey = PageProductGapDetectionService::norm((string) $snapshot['brand']);

        usort($links, function ($a, $b) use ($brandKey) {
            $score = static fn ($u) => ($brandKey !== '' && str_contains(PageProductGapDetectionService::norm((string) parse_url($u, PHP_URL_HOST)), $brandKey)) ? 1 : 0;

            return $score($b) <=> $score($a);
        });

        $specs = [];
        $sources = [];

        $urls = array_slice($links, 0, max(1, (int) ($cfg['max_pages'] ?? 3)));

        // صفحه‌ها هم‌زمان گرفته می‌شوند؛ زمان کل ≈ کندترین صفحه، نه مجموع همه
        try {
            $pages = Http::pool(function (Pool $pool) use ($urls) {
                foreach ($urls as $i => $url) {
                    $pool->as((string) $i)
                        ->withUserAgent('Mozilla/5.0 (compatible; YoutixSeo/1.0)')
                        ->connectTimeout(4)
                        ->timeout(8)
                        ->get($url);
                }
            });
        } catch (Throwable $e) {
            Log::warning('[PageProductWebSpecSearch] fetching pages failed', ['error' => $e->getMessage()]);

            return $empty;
        }

        foreach ($urls as $i => $url) {
            $page = $pages[(string) $i] ?? null;

            if (! $page instanceof Response || ! $page->successful()) {
                continue;
            }

            $found = $this->analyze($page->body(), $snapshot);

            if ($found === null) {
                continue; // صفحه‌ی مربوط به همین محصول/مدل نیست
            }

            foreach ($found as $name => $value) {
                $key = PageProductGapDetectionService::norm($name);

                if (! isset($specs[$key]) && count($specs) < self::MAX_SPECS) {
                    $specs[$key] = ['name' => $name, 'value' => $value, 'source' => $url];
                }
            }

            if ($found !== []) {
                $sources[] = $url;
            }
        }

        return [
            'verified' => $specs !== [],
            'specs' => array_values($specs),
            'sources' => $sources,
        ];
    }

    protected function isListingPage(string $url): bool
    {
        $lower = mb_strtolower($url);

        foreach (self::LISTING_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,string>|null  null = صفحه با محصول تطبیق ندارد
     */
    protected function analyze(string $html, array $snapshot): ?array
    {
        $product = $this->jsonLdProduct($html);

        $title = '';
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//title|//h1') as $node) {
            $title .= ' '.$node->textContent;
        }

        $haystack = PageProductGapDetectionService::norm($title.' '.($product['name'] ?? '').' '.($product['mpn'] ?? '').' '.($product['sku'] ?? '').' '.($product['model'] ?? ''));

        if (! $this->matches($snapshot, $haystack)) {
            return null;
        }

        $specs = [];

        foreach ((array) ($product['additionalProperty'] ?? []) as $prop) {
            if (is_array($prop) && isset($prop['name']) && isset($prop['value']) && is_scalar($prop['value'])) {
                $specs[trim((string) $prop['name'])] = trim((string) $prop['value']);
            }
        }

        foreach (self::DIMENSION_LABELS as $key => $label) {
            $v = $product[$key] ?? null;

            if (is_array($v) && isset($v['value'])) {
                $specs[$label] = trim($v['value'].' '.($v['unitText'] ?? $v['unitCode'] ?? ''));
            }
        }

        foreach (self::SIMPLE_LABELS as $key => $label) {
            if (isset($product[$key]) && is_scalar($product[$key])) {
                $specs[$label] = trim((string) $product[$key]);
            }
        }

        // جدول‌های دو‌ستونی مشخصات در خود صفحه
        foreach ($xpath->query('//tr') as $row) {
            $cells = $xpath->query('./th|./td', $row);

            if ($cells->length !== 2) {
                continue;
            }

            $label = trim($cells->item(0)->textContent);
            $value = trim((string) preg_replace('/\s+/u', ' ', $cells->item(1)->textContent));

            if ($label !== '' && $value !== '' && mb_strlen($label) <= 60 && mb_strlen($value) <= 150) {
                $specs[$label] = $value;
            }
        }

        return array_filter($specs, static fn ($v) => $v !== '');
    }

    /**
     * تطبیق: اگر مدل قابل‌اتکا (حداقل ۴ کاراکتر) داریم باید در عنوان/JSON-LD باشد؛
     * وگرنه برند و بیشتر کلمات نام.
     */
    protected function matches(array $snapshot, string $haystack): bool
    {
        $model = PageProductGapDetectionService::norm((string) ($snapshot['model'] ?? ''));

        if (mb_strlen($model) >= self::MIN_MODEL_LENGTH) {
            return str_contains($haystack, $model);
        }

        if (! empty($snapshot['brand']) && ! str_contains($haystack, PageProductGapDetectionService::norm($snapshot['brand']))) {
            return false;
        }

        $tokens = array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', (string) $snapshot['name']) ?: [],
            static fn ($t) => mb_strlen($t) >= 3
        );

        if ($tokens === []) {
            return false;
        }

        $hits = count(array_filter($tokens, static fn ($t) => str_contains($haystack, PageProductGapDetectionService::norm($t))));

        return $hits / count($tokens) >= 0.6;
    }

    protected function jsonLdProduct(string $html): array
    {
        if (stripos($html, 'ld+json') === false) {
            return [];
        }

        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

        foreach ($matches[1] ?? [] as $raw) {
            $block = json_decode(trim($raw), true);

            if (! is_array($block)) {
                continue;
            }

            $nodes = isset($block['@graph']) && is_array($block['@graph'])
                ? $block['@graph']
                : (array_is_list($block) ? $block : [$block]);

            foreach ($nodes as $node) {
                if (is_array($node) && in_array('Product', (array) ($node['@type'] ?? []), true)) {
                    return $node;
                }
            }
        }

        return [];
    }
}
