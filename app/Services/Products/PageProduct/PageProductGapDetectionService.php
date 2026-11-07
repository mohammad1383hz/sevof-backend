<?php

namespace App\Services\Products\PageProduct;

use App\Models\SiteProduct;
use App\Models\SiteProductCategory;

/**
 * مرحله ۱ و ۳: خواندن اطلاعات فعلی محصول و تشخیص نقص‌ها؛ کاملاً بدون AI.
 *
 * «نقص» فقط خالی بودن نیست: توضیحات خیلی کوتاه هم نقص حساب می‌شود (آستانه‌ها در
 * config/page_product_seo.php با کلیدهای min_description_words و min_short_description_words).
 */
class PageProductGapDetectionService
{
    private const BRAND_KEYS = ['برند', 'brand', 'pa_brand'];
    private const MODEL_KEYS = ['مدل', 'model', 'mpn', 'شناسه سازنده', 'pa_model'];

    private const FAQ_PHRASES = [
        'سوالات متداول', 'سؤالات متداول', 'پرسش‌های متداول', 'پرسش های متداول', 'پرسش و پاسخ', 'faq',
    ];

    // سقف بالاتر تا alt تصاویر گالری‌های بزرگ هم پوشش داده شود
    private const MAX_IMAGES = 30;

    /**
     * @param  array<int|string, string>  $categories  id => name
     * @param  bool  $shopify  Shopify product: no short description, focus keyword or attributes
     */
    public function snapshot(SiteProduct $product, array $categories = [], bool $shopify = false): array
    {
        $raw = $this->decode($product->raw_data ?? null);

        $attributes = [];

        foreach ((array) ($raw['attributes'] ?? []) as $attr) {
            if (! is_array($attr)) {
                continue;
            }

            $name = trim((string) ($attr['name'] ?? $attr['label'] ?? ''));
            $value = $attr['options'] ?? $attr['value'] ?? '';

            if (is_array($value)) {
                $value = implode('، ', array_filter(array_map(
                    static fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
                    $value
                )));
            }

            $value = trim(strip_tags((string) $value));

            if ($name !== '' && $value !== '') {
                $attributes[] = ['name' => $name, 'value' => $value];
            }
        }

        $brand = $this->attr($attributes, self::BRAND_KEYS);
        $brand ??= is_array($raw['brands'][0] ?? null) ? ($raw['brands'][0]['name'] ?? null) : null;

        // Shopify has no short description; ignore the column so no false gap is reported
        $short = $shopify ? '' : $this->plain($product->short_description);
        // بلوک FAQ جزو شمارش کلمات توضیحات نیست
        $description = $this->plain(ProductContentSanitizer::stripFaqBlock((string) $product->description));
        $categoryLink = $this->categoryLink($product);

        return [
            'id' => $product->id,
            'platform' => $shopify ? 'shopify' : 'wordpress',
            'name' => html_entity_decode((string) $product->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'brand' => $brand ? trim((string) $brand) : null,
            'category' => $categories[$product->category_id] ?? null,
            'model' => $this->attr($attributes, self::MODEL_KEYS) ?? (trim((string) $product->sku) ?: null),
            'short_description' => $short,
            'description' => $description,
            'short_words' => $this->words($short),
            'description_words' => $this->words($description),
            'has_faq' => $this->hasFaq((string) $product->description),
            'category_link' => $categoryLink,
            'has_category_link' => $categoryLink !== null
                && ProductContentSanitizer::hasLinkTo((string) $product->description, $categoryLink['url']),
            'tags' => $this->tags($raw),
            'images' => $this->images($raw, $product->images ?? null),
            'attributes' => $attributes,
            'seller_text' => implode(' ', array_filter([
                $product->name, $short, $description,
                implode(' ', array_column($attributes, 'value')),
            ])),
        ];
    }

    /**
     * @return list<string> فیلدهای ناقص. سئو فقط وقتی «ناقص» حساب می‌شود که قبلاً منتشر نشده باشد.
     */
    public function missing(array $snapshot, bool $seoDone): array
    {
        $missing = [];
        $shopify = ($snapshot['platform'] ?? 'wordpress') === 'shopify';

        $minShort = (int) config('page_product_seo.min_short_description_words', 8);
        $minDescription = (int) config('page_product_seo.min_description_words', 20);

        // Shopify: no short description or focus keyword, so these are never gaps
        if (! $shopify && ($snapshot['short_description'] === '' || $snapshot['short_words'] < $minShort)) {
            $missing[] = 'short_description';
        }

        if ($snapshot['description'] === '' || $snapshot['description_words'] < $minDescription) {
            $missing[] = 'description';
        }

        // توضیحات به دسته‌ی محصول لینک نمی‌دهد (چه توضیحات تولید شود چه فقط لینک به متن فعلی اضافه شود)
        if (! empty($snapshot['category_link']) && empty($snapshot['has_category_link'])) {
            $missing[] = 'category_link';
        }

        // Shopify products have no free-form attributes to write back
        if (! $shopify && $snapshot['attributes'] === []) {
            $missing[] = 'attributes';
        }

        if ($snapshot['tags'] === []) {
            $missing[] = 'tags';
        }

        if (! $snapshot['has_faq']) {
            $missing[] = 'faq';
        }

        if ($this->imagesMissingAlt($snapshot['images']) !== []) {
            $missing[] = 'image_alts';
        }

        if (! $seoDone) {
            $missing[] = 'seo_title';
            $missing[] = 'meta_description';

            if (! $shopify) {
                $missing[] = 'focus_keyword';
            }
        }

        return $missing;
    }

    /**
     * دسته‌ی محصول با آدرس صفحه‌اش؛ دسته‌ی بدون URL معتبر (مثلاً crawl‌شده) لینک‌شدنی نیست.
     *
     * @return array{name:string,url:string}|null
     */
    public function categoryLink(SiteProduct $product): ?array
    {
        $category = $product->category_id ? $product->category : null;

        if (! $category instanceof SiteProductCategory) {
            return null;
        }

        $name = trim(html_entity_decode((string) $category->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = trim((string) $category->url);

        return $name !== '' && preg_match('#^https?://#i', $url) ? ['name' => $name, 'url' => $url] : null;
    }

    /**
     * تصاویر بدون alt که قابل رفع‌اند. alt فقط با id پیوست وردپرس نوشته می‌شود،
     * پس تصویری که فقط URL دارد (id = null) نقص قابل‌رفع حساب نمی‌شود.
     *
     * @return list<array{id:int,src:string,alt:string}>
     */
    public function imagesMissingAlt(array $images): array
    {
        return array_values(array_filter(
            $images,
            static fn (array $i): bool => $i['id'] !== null && $i['alt'] === ''
        ));
    }

    /** نرمال‌سازی برای مقایسه: ارقام فارسی/عربی، حروف کوچک، حذف فاصله و علائم. */
    public static function norm(string $value): string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ك' => 'ک',
        ]);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }

    private function attr(array $attributes, array $keys): ?string
    {
        foreach ($attributes as $a) {
            if (in_array(mb_strtolower($a['name']), $keys, true)) {
                return $a['value'];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function tags(array $raw): array
    {
        $out = [];

        foreach ((array) ($raw['tags'] ?? []) as $t) {
            $name = is_array($t) ? ($t['name'] ?? '') : $t;

            if (! is_scalar($name)) {
                continue;
            }

            $name = trim(html_entity_decode(strip_tags((string) $name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @return list<array{id:int|null,src:string,alt:string}>
     */
    private function images(array $raw, mixed $fallback): array
    {
        $list = $raw['images'] ?? null;

        if (! is_array($list) || $list === []) {
            $list = $this->decode($fallback);
        }

        $out = [];

        foreach ($list as $img) {
            if (is_string($img)) {
                $src = trim($img);
                $id = null;
                $alt = '';
            } elseif (is_array($img)) {
                $src = trim((string) ($img['src'] ?? $img['url'] ?? ''));
                $id = (int) ($img['id'] ?? 0) > 0 ? (int) $img['id'] : null;
                $alt = trim((string) ($img['alt'] ?? ''));
            } else {
                continue;
            }

            if ($src === '' && $id === null) {
                continue;
            }

            $out[] = ['id' => $id, 'src' => $src, 'alt' => $alt];

            if (count($out) >= self::MAX_IMAGES) {
                break;
            }
        }

        return $out;
    }

    private function hasFaq(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        if (str_contains($html, ProductContentSanitizer::FAQ_START)) {
            return true;
        }

        $text = self::norm(strip_tags($html));

        foreach (self::FAQ_PHRASES as $phrase) {
            if (str_contains($text, self::norm($phrase))) {
                return true;
            }
        }

        return false;
    }

    /** نیم‌فاصله (ZWNJ) بخشی از کلمه حساب می‌شود. */
    private function words(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}\x{200C}]+/u', $text);
    }

    /** بین تگ‌های بلوکی فاصله می‌گذارد تا کلمات به هم نچسبند. */
    private function plain(mixed $html): string
    {
        $html = (string) preg_replace('#</?(p|div|h[1-6]|li|ul|ol|br|tr|td)\b[^>]*>#i', ' ', (string) $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function decode(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
