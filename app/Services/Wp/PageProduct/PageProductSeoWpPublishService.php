<?php

namespace App\Services\Wp\PageProduct;

use App\Models\PageProduct\PageProductSeoOptimization;
use App\Models\SiteProduct;
use App\Services\Products\PageProduct\PageProductGapDetectionService;
use App\Services\Products\PageProduct\ProductContentSanitizer;
use App\Services\Wp\ArticlePublishService;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\Log;

/**
 * ویرایش «محصول» در ووکامرس با نتیجه‌ی تأییدشده‌ی سئو.
 * Networking and signing are inherited from ArticlePublishService;
 * اینجا فقط ساخت payload و مدیریت وضعیت PageProductSeoOptimization است.
 *
 * Plugin side: Sevof Connector 1.0.0+
 *   PUT sevof-connector/v1/products/{id}
 * کلیدهای payload: short_description, description, meta_title, meta_description, focus_keyword,
 *                  attributes, tags, faq, image_alts
 * پاسخ پلاگین: updated_fields, preserved_fields, skipped_attributes, image_alts_written,
 *              added_attributes, style_mode, style_reason, faq_schema
 *
 * سقف‌های پلاگین (اگر یکی رد شود کل PUT با 400 رد می‌شود؛ پس همه قبل از ارسال اعمال می‌شوند):
 *   tags ≤ 20 (هر تگ ۲ تا ۴۰ کاراکتر) | faq ≤ 8 (سؤال ≤ 300، پاسخ ≤ 1500 کاراکتر)
 *   attributes ≤ 30 (نام ≤ 200 بایت، مقدار ≤ 1000 بایت) | image_alts ≤ 50
 *   meta_title / meta_description ≤ 1000 بایت | focus_keyword ≤ 800 بایت
 *   short_description ≤ 20000 بایت | description ≤ 200000 بایت
 */
class PageProductSeoWpPublishService extends ArticlePublishService
{
    private const ACTION_UPDATE = 'update';

    private const DEFAULT_PLUGIN_ROUTE = '/index.php?rest_route=/sevof-connector/v1/products';
    private const DEFAULT_SIGNING_PATH = 'sevof-connector/v1/products';

    private const BASE_KEYS = ['payload_version', 'source_type', 'product_id'];
    private const RESPONSE_KEYS = ['data', 'body', 'response', 'json'];
    private const IMAGE_ALT_MAX = 125;

    /** باید با نشانگرهای پلاگین (Youtix_Sync_Product_Controller::DESC_START/END) یکی باشد. */
    private const DESC_START = '<!--sevof-desc-->';
    private const DESC_END = '<!--/sevof-desc-->';

    /** سقف‌های پلاگین. */
    private const MAX_TAGS = 20;
    private const MIN_TAG_CHARS = 2;
    private const MAX_TAG_CHARS = 40;
    private const MAX_FAQ_ITEMS = 8;
    private const MAX_FAQ_QUESTION_CHARS = 300;
    private const MAX_FAQ_ANSWER_CHARS = 1500;
    private const MAX_ATTRIBUTES = 30;
    private const MAX_ATTRIBUTE_NAME_BYTES = 200;
    private const MAX_ATTRIBUTE_VALUE_BYTES = 1000;
    private const MAX_IMAGE_ALTS = 50;
    private const MAX_META_BYTES = 1000;
    private const MAX_FOCUS_KEYWORD_BYTES = 800;
    private const MAX_SHORT_DESCRIPTION_BYTES = 20000;
    private const MAX_DESCRIPTION_BYTES = 200000;

    /** طول مجاز ستون publish_error (برای اینکه نوشتن هشدار هیچ‌وقت خطای دیتابیس نده). */
    private const PUBLISH_NOTE_MAX = 300;

    private const FIELD_LABELS = [
        'short_description' => 'short description',
        'description' => 'description',
        'meta_title' => 'SEO title',
        'meta_description' => 'meta description',
        'focus_keyword' => 'focus keyword',
        'attributes' => 'attributes',
        'tags' => 'tags',
        'faq' => 'FAQ',
        'image_alts' => 'image alt text',
    ];

    private const WP_ERROR_MESSAGES = [
        'sevof_product_not_found' => 'This product no longer exists in WooCommerce.',
        'sevof_woocommerce_inactive' => 'WooCommerce is not active on this site.',
        'sevof_product_nothing_to_update' => 'No sendable fields reached the plugin.',
        'sevof_product_update_exception' => 'The plugin failed while saving the product in WooCommerce.',
        'sevof_unauthorized' => 'WordPress rejected the API key or request signature; check the site connection again.',
        'sevof_replay_detected' => 'WordPress just processed this request (duplicate); try again in a few seconds.',
        'rest_invalid_param' => 'One of the fields exceeds the plugin\'s limit or has an invalid format.',
        'rest_no_route' => 'The Sevof plugin on this site is outdated and can\'t edit products. Install the latest Sevof Connector.',
    ];

    /** @var list<string> یادداشت‌های کوتاه‌سازی/حذف فیلد به‌خاطر سقف پلاگین (برای هر درخواست). */
    private array $limitNotes = [];

    /**
     * رفع «لینک دسته» روی محصولی که حالت نمایشش معلوم نیست: اگر پلاگین themed جواب داد، متن فروشنده را
     * نگه داشته و کپی ما را هم اضافه کرده؛ این جمله‌ی لینک‌دار جای آن کپی فرستاده می‌شود.
     */
    private ?string $themedLinkFix = null;

    public function updateProduct(PageProductSeoOptimization $optimization): array
    {
        $this->limitNotes = [];
        $this->themedLinkFix = null;

        if (! in_array($optimization->status, [PageProductSeoOptimization::STATUS_APPROVED, PageProductSeoOptimization::STATUS_READY], true)) {
            return $this->failProductSeo($optimization, 'The SEO result isn\'t ready to publish yet.');
        }

        $product = $optimization->product;
        $connection = $optimization->site->connection;

        if (! $connection) {
            return $this->failProductSeo($optimization, 'This site is not connected to WordPress yet.');
        }

        if (! $product || ! $product->external_id) {
            return $this->failProductSeo($optimization, 'The product\'s WooCommerce ID was not found.');
        }

        try {
            $payload = $this->buildSeoPayload($optimization, $product);
        } catch (\Throwable $e) {
            return $this->failProductSeo($optimization, $e->getMessage());
        }

        $optimization->update(['wp_sync_status' => 'syncing']);

        $path = $this->productRoute('plugin_routes', self::DEFAULT_PLUGIN_ROUTE) . '/' . $product->external_id;
        $signingPath = $this->productRoute('signing_paths', self::DEFAULT_SIGNING_PATH) . '/' . $product->external_id;

        Log::info('wp_page_product_seo.request', [
            'optimization_id' => $optimization->id,
            'path' => $path,
            'signing_path' => $signingPath,
        ]);

        try {
            $result = $this->call($connection, 'PUT', $path, $payload, $signingPath);
        } catch (\Throwable $e) {
            return $this->failProductSeo($optimization, 'WordPress request failed: ' . mb_substr($e->getMessage(), 0, 300));
        }

        $this->logSeoResult($optimization, $result);

        if (($result['ok'] ?? false) && $this->themedLinkFix !== null && $this->styleMode($result) === 'themed') {
            $payload = $this->replaceThemedCopy($optimization, $connection, $path, $signingPath, $payload);
        }

        if (! ($result['ok'] ?? false)) {
            $optimization->update([
                'status' => PageProductSeoOptimization::STATUS_READY,
                'wp_sync_status' => 'failed',
                'publish_error' => $this->clip($this->errorMessage($result)),
            ]);

            return $result;
        }

        $outcome = $this->appliedPayload($optimization, $payload, $result);
        $notes = $this->publishNotes($payload, $outcome['ignored'], $result);

        // هشدارها (فیلد ذخیره‌نشده، حفظ‌شده، ردشده) در publish_error می‌مانند؛ فقط وقتی هیچ هشداری نیست null می‌شود.
        $optimization->update([
            'status' => PageProductSeoOptimization::STATUS_PUBLISHED,
            'wp_sync_status' => 'synced',
            'publish_error' => $notes === [] ? null : $this->clip(implode(' ', $notes)),
            'published_at' => now(),
            'last_synced_at' => now(),
        ]);

        $this->syncLocalProduct($product, $outcome['applied'], $this->styleMode($result));

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  Payload                                                             */
    /* ------------------------------------------------------------------ */

    private function buildSeoPayload(PageProductSeoOptimization $optimization, SiteProduct $product): array
    {
        $result = (array) $optimization->result;
        $approved = array_values(array_intersect((array) $optimization->approved_fields, array_keys($result)));
        $has = static fn (string $f): bool => in_array($f, $approved, true);

        $snapshot = app(PageProductGapDetectionService::class)->snapshot($product);

        $payload = [
            'payload_version' => self::PAYLOAD_VERSION,
            'source_type' => 'product_seo',
            'product_id' => (int) $product->external_id,
        ];

        // متن‌های تک‌خطی (کلید خالی ارسال نمی‌شود؛ پلاگین هم هیچ‌وقت داده را پاک نمی‌کند)
        $singleLines = [
            'seo_title' => ['meta_title', self::MAX_META_BYTES],
            'meta_description' => ['meta_description', self::MAX_META_BYTES],
            'focus_keyword' => ['focus_keyword', self::MAX_FOCUS_KEYWORD_BYTES],
        ];

        foreach ($singleLines as $field => [$key, $maxBytes]) {
            $value = $this->cutBytes(ProductContentSanitizer::plainLine((string) ($result[$field] ?? '')), $maxBytes, $key);

            if ($has($field) && $value !== '') {
                $payload[$key] = $value;
            }
        }

        if ($has('short_description')) {
            $short = ProductContentSanitizer::html((string) ($result['short_description'] ?? ''));

            if ($short !== '' && strlen($short) > self::MAX_SHORT_DESCRIPTION_BYTES) {
                $this->limitNotes[] = 'The short description exceeded the plugin\'s size limit and was not sent.';
                $short = '';
            }

            if ($short !== '') {
                $payload['short_description'] = $short;
            }
        }

        // FAQ: ساختار جدا فرستاده می‌شود؛ نشانگرها را پلاگین می‌گذارد
        if ($has('faq')) {
            $faq = $this->limitFaq(ProductContentSanitizer::faq((array) ($result['faq'] ?? [])));

            if ($faq !== []) {
                $payload['faq'] = $faq;
            }
        }

        // لینک دسته: هر توضیحاتی که منتشر می‌شود (تولیدی یا ویرایش‌شده) به صفحه‌ی دسته‌ی محصول لینک می‌دهد
        $link = app(PageProductGapDetectionService::class)->categoryLink($product);
        $persian = ContentLanguage::isPersian(ContentLanguage::forSite($optimization->site));
        $linked = static fn (string $html): string => $link && trim($html) !== ''
            ? ProductContentSanitizer::withCategoryLink($html, $link['name'], $link['url'], $persian)
            : $html;

        // توضیحات: اول بلوک FAQ و نشانگرها حذف، بعد sanitize. اگر faq نیامده باشد، پلاگین FAQ فعلی را نگه می‌دارد.
        if ($has('description') || ($has('category_link') && $link)) {
            $body = $has('description')
                ? $linked(ProductContentSanitizer::html(
                    ProductContentSanitizer::stripFaqBlock((string) ($result['description'] ?? ''))
                ))
                : $this->categoryLinkOnlyDescription($product, $link, $persian, $linked);

            if ($body !== '' && strlen($body) > self::MAX_DESCRIPTION_BYTES) {
                $this->limitNotes[] = 'The description exceeded the plugin\'s size limit and was not sent.';
                $body = '';
            }

            if ($body !== '') {
                $payload['description'] = $body;
            }
        }

        // تگ: فقط تگ‌های جدید (پلاگین append می‌کند)
        if ($has('tags')) {
            $tags = $this->limitTags(ProductContentSanitizer::tags((array) ($result['tags'] ?? []), $snapshot['tags'] ?? []));

            if ($tags !== []) {
                $payload['tags'] = $tags;
            }
        }

        // alt تصاویر: فقط تصویرِ همین محصول که الان alt ندارد (null و رشته‌ی خالی/فاصله هر دو «بدون alt»)
        if ($has('image_alts')) {
            $current = [];

            foreach ((array) ($snapshot['images'] ?? []) as $img) {
                if (is_array($img) && ($img['id'] ?? null) !== null) {
                    $current[(int) $img['id']] = trim((string) ($img['alt'] ?? ''));
                }
            }

            $alts = [];

            foreach ((array) ($result['image_alts'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $id = (int) ($row['id'] ?? 0);
                $alt = ProductContentSanitizer::limitAtWord(
                    ProductContentSanitizer::plainLine((string) ($row['alt'] ?? '')),
                    self::IMAGE_ALT_MAX
                );

                if ($id > 0 && $alt !== '' && array_key_exists($id, $current) && $current[$id] === '') {
                    $alts[$id] = ['id' => $id, 'alt' => $alt];
                }
            }

            if (count($alts) > self::MAX_IMAGE_ALTS) {
                $this->limitNotes[] = 'Image alt text was limited to ' . self::MAX_IMAGE_ALTS . ' items.';
                $alts = array_slice($alts, 0, self::MAX_IMAGE_ALTS, true);
            }

            if ($alts !== []) {
                $payload['image_alts'] = array_values($alts);
            }
        }

        if ($has('attributes')) {
            $attributes = [];
            $dropped = 0;

            foreach ((array) ($result['attributes'] ?? []) as $a) {
                if (! is_array($a) || ($a['verified'] ?? null) === false) {
                    continue;
                }

                $name = ProductContentSanitizer::plainLine((string) ($a['name'] ?? ''));
                // پلاگین «|» را به «/» تبدیل می‌کند؛ همین‌جا هم یکسان می‌شود تا نسخه‌ی محلی با ووکامرس برابر باشد.
                $value = trim(str_replace('|', '/', ProductContentSanitizer::plainLine((string) ($a['value'] ?? ''))));

                if ($name === '' || $value === '') {
                    continue;
                }

                if (strlen($name) > self::MAX_ATTRIBUTE_NAME_BYTES || strlen($value) > self::MAX_ATTRIBUTE_VALUE_BYTES) {
                    $dropped++;

                    continue;
                }

                $attributes[] = ['name' => $name, 'value' => $value];
            }

            if ($dropped > 0) {
                $this->limitNotes[] = $dropped . ' attributes were too long and were not sent.';
            }

            if (count($attributes) > self::MAX_ATTRIBUTES) {
                $this->limitNotes[] = 'Attributes were limited to ' . self::MAX_ATTRIBUTES . ' items.';
                $attributes = array_slice($attributes, 0, self::MAX_ATTRIBUTES);
            }

            if ($attributes !== []) {
                $payload['attributes'] = $attributes;
            }
        }

        if (count($payload) === count(self::BASE_KEYS)) {
            $reason = $this->limitNotes !== [] ? ' ' . implode(' ', $this->limitNotes) : '';

            throw new \RuntimeException('There are no approved fields to send.' . $reason);
        }

        Log::info('wp_page_product_seo.payload_built', [
            'optimization_id' => $optimization->id,
            'fields' => array_keys($payload),
            'limit_notes' => $this->limitNotes,
        ]);

        return $payload;
    }

    /**
     * فقط افزودن لینک دسته به توضیحات فعلی (بدون بازنویسی متن فروشنده):
     *  - بلوک متن سئوی قبلی ما (themed) وجود دارد → فقط همان بلوک با لینک فرستاده می‌شود.
     *  - وگرنه متن فعلی فروشنده بدون sanitize (تا تصویر/لینک/قالبش از بین نرود) با لینک فرستاده می‌شود؛
     *    branded همین را جایگزین می‌کند و برای themed بعد از پاسخ، replaceThemedCopy اصلاحش می‌کند.
     *
     * @param  array{name:string,url:string}  $link
     */
    private function categoryLinkOnlyDescription(SiteProduct $product, array $link, bool $persian, \Closure $linked): string
    {
        $current = (string) $product->description;
        $seoBlock = $this->markedBlock($current, self::DESC_START, self::DESC_END);

        if ($seoBlock !== '') {
            return $linked($seoBlock);
        }

        $store = $this->stripYoutixBlocks($current);

        if ($store === '') {
            return '';
        }

        $this->themedLinkFix = ProductContentSanitizer::withCategoryLink('', $link['name'], $link['url'], $persian);

        return $linked($store);
    }

    /**
     * پلاگین themed بود: متن فروشنده را دست‌نخورده نگه داشته و کپی لینک‌دارِ ما را هم در بلوک سئو گذاشته.
     * آن بلوک با فقط جمله‌ی لینک‌دار جایگزین می‌شود تا متن دوبار نمایش داده نشود.
     */
    private function replaceThemedCopy(PageProductSeoOptimization $optimization, $connection, string $path, string $signingPath, array $payload): array
    {
        $fixPayload = array_intersect_key($payload, array_flip(self::BASE_KEYS)) + ['description' => $this->themedLinkFix];

        try {
            $fix = $this->call($connection, 'PUT', $path, $fixPayload, $signingPath);
        } catch (\Throwable $e) {
            $fix = ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($fix['ok'] ?? false) {
            $payload['description'] = $this->themedLinkFix;

            return $payload;
        }

        Log::warning('wp_page_product_seo.themed_link_fix_failed', [
            'optimization_id' => $optimization->id,
            'error' => $fix['message'] ?? null,
        ]);

        $this->limitNotes[] = 'The category link was added, but the description may now appear twice on the product page; please check it.';

        return $payload;
    }

    /**
     * تگ‌ها طبق قانون پلاگین: ۲ تا ۴۰ کاراکتر (بقیه را پلاگین بی‌صدا حذف می‌کند)، یکتا، حداکثر ۲۰ تا.
     * بیش از ۲۰ تا کل درخواست را با 400 رد می‌کند.
     *
     * @param  array<int, mixed>  $tags
     * @return list<string>
     */
    private function limitTags(array $tags): array
    {
        $out = [];
        $seen = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $tag = trim($tag);
            $len = mb_strlen($tag);
            $key = $this->normalizeLabel($tag);

            if ($len < self::MIN_TAG_CHARS || $len > self::MAX_TAG_CHARS || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $tag;
        }

        if (count($out) > self::MAX_TAGS) {
            $this->limitNotes[] = 'Tags were limited to ' . self::MAX_TAGS . ' items.';
            $out = array_slice($out, 0, self::MAX_TAGS);
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $faq
     * @return list<array{question: string, answer: string}>
     */
    private function limitFaq(array $faq): array
    {
        $out = [];

        foreach ($faq as $row) {
            if (! is_array($row)) {
                continue;
            }

            $question = $this->cutChars(trim((string) ($row['question'] ?? '')), self::MAX_FAQ_QUESTION_CHARS);
            $answer = $this->cutChars(trim((string) ($row['answer'] ?? '')), self::MAX_FAQ_ANSWER_CHARS);

            if ($question !== '' && $answer !== '') {
                $out[] = ['question' => $question, 'answer' => $answer];
            }
        }

        if (count($out) > self::MAX_FAQ_ITEMS) {
            $this->limitNotes[] = 'FAQ was limited to ' . self::MAX_FAQ_ITEMS . ' items.';
            $out = array_slice($out, 0, self::MAX_FAQ_ITEMS);
        }

        return $out;
    }

    /** کوتاه‌کردن بر اساس کاراکتر، ترجیحاً سر پایان جمله یا فاصله. */
    private function cutChars(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $floor = (int) floor($max * 0.6);

        if (preg_match('/^(.{' . $floor . ',}[.!?؟!؛])(?!.*[.!?؟!؛])/us', $cut, $m)) {
            return trim($m[1]);
        }

        $space = mb_strrpos($cut, ' ');

        return trim($space !== false && $space >= $floor ? mb_substr($cut, 0, $space) : $cut);
    }

    /** کوتاه‌کردن بر اساس بایت (محدودیت پلاگین با strlen است و فارسی چندبایتی)، بدون شکستن کاراکتر. */
    private function cutBytes(string $text, int $maxBytes, string $label): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $this->limitNotes[] = (self::FIELD_LABELS[$label] ?? $label) . ' was shortened to the plugin\'s limit.';

        $cut = mb_strcut($text, 0, $maxBytes, 'UTF-8');
        $space = mb_strrpos($cut, ' ');

        return trim($space !== false && $space >= (int) (mb_strlen($cut) * 0.6) ? mb_substr($cut, 0, $space) : $cut);
    }

    /**
     * فقط فیلدهایی که پلاگین تأیید کرده ذخیره شده‌اند به نسخه‌ی محلی می‌روند.
     * اگر پلاگین updated_fields نداده باشد (پلاگین قدیمی)، هشدار لاگ می‌شود و کل payload فرض می‌شود.
     *
     * هیچ چیزی در دیتابیس نمی‌نویسد؛ پیام‌ها را updateProduct با یک update یکجا ذخیره می‌کند
     * (قبلاً publish_error اینجا نوشته و بلافاصله null می‌شد).
     *
     * @return array{applied: array<string, mixed>, ignored: list<string>}
     */
    private function appliedPayload(PageProductSeoOptimization $optimization, array $payload, array $result): array
    {
        $updated = $this->updatedFields($result);
        $sent = array_values(array_diff(array_keys($payload), self::BASE_KEYS));

        if ($updated === null) {
            Log::warning('wp_page_product_seo.plugin_no_updated_fields', [
                'optimization_id' => $optimization->id,
                'sent' => $sent,
            ]);

            return ['applied' => $payload, 'ignored' => []];
        }

        $ignored = array_values(array_diff($sent, $updated));

        if ($ignored !== []) {
            Log::warning('wp_page_product_seo.fields_ignored_by_plugin', [
                'optimization_id' => $optimization->id,
                'ignored' => $ignored,
                'preserved' => $this->stringList($this->responseField($result, 'preserved_fields')),
            ]);
        }

        return [
            'applied' => array_intersect_key($payload, array_flip(array_merge(self::BASE_KEYS, $updated))),
            'ignored' => $ignored,
        ];
    }

    /** @return list<string>|null */
    private function updatedFields(array $result): ?array
    {
        $list = $this->responseField($result, 'updated_fields');

        return is_array($list) ? $this->stringList($list) : null;
    }

    /**
     * مقدار یک کلید از بدنه‌ی پاسخ پلاگین (داخل data/body/response/json یا سطح بالا).
     *
     * @return mixed|null
     */
    private function responseField(array $result, string $field)
    {
        foreach (self::RESPONSE_KEYS as $key) {
            if (is_array($result[$key] ?? null) && array_key_exists($field, $result[$key])) {
                return $result[$key][$field];
            }
        }

        return $result[$field] ?? null;
    }

    /** @return list<string> */
    private function stringList($value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** حالت نمایش محصول در پلاگین؛ اگر نیامد (پلاگین قدیمی که جایگزین می‌کند) branded فرض می‌شود. */
    private function styleMode(array $result): string
    {
        return $this->responseField($result, 'style_mode') === 'themed' ? 'themed' : 'branded';
    }

    /**
     * پیام‌های قابل‌فهم برای کاربر از روی پاسخ پلاگین.
     *
     * @param  list<string>  $ignored  فیلدهایی که فرستاده شد ولی در updated_fields نبود
     * @return list<string>
     */
    private function publishNotes(array $payload, array $ignored, array $result): array
    {
        $notes = $this->limitNotes;

        $preserved = $this->stringList($this->responseField($result, 'preserved_fields'));
        $skippedAttributes = $this->stringList($this->responseField($result, 'skipped_attributes'));
        $generic = [];

        foreach ($ignored as $field) {
            $label = self::FIELD_LABELS[$field] ?? $field;

            if (in_array($field, $preserved, true)) {
                $notes[] = $field === 'short_description'
                    ? "{$label} was kept because the store uses its own styling for it."
                    : "{$label} was kept because it already has a value.";

                continue;
            }

            if ($field === 'attributes' && $skippedAttributes !== []) {
                $notes[] = 'No new attributes were added; these already existed: ' . implode('، ', $skippedAttributes) . '.';

                continue;
            }

            if ($field === 'tags') {
                $notes[] = 'No new tags were added (all were already on the product).';

                continue;
            }

            if ($field === 'image_alts') {
                $notes[] = 'No image alt text was saved (the image didn\'t belong to this product or already had alt text).';

                continue;
            }

            $generic[] = $label;
        }

        if ($generic !== []) {
            $notes[] = 'The plugin did not save these fields: ' . implode('، ', $generic) . '.';
        }

        // موردهایی که فیلد ذخیره شده ولی بخشی از آن رد شده
        if ($skippedAttributes !== [] && ! in_array('attributes', $ignored, true) && isset($payload['attributes'])) {
            $notes[] = 'Duplicate attributes were skipped: ' . implode('، ', $skippedAttributes) . '.';
        }

        $written = $this->responseField($result, 'image_alts_written');
        $sentAlts = isset($payload['image_alts']) ? count($payload['image_alts']) : 0;

        if (is_numeric($written) && (int) $written > 0 && (int) $written < $sentAlts) {
            $notes[] = 'Alt text was saved for only ' . (int) $written . ' of ' . $sentAlts . ' images.';
        }

        if ($this->responseField($result, 'faq_schema') === false
            && isset($payload['faq'])
            && ! in_array('faq', $ignored, true)) {
            $notes[] = 'FAQ schema is not printed (the product uses a page builder or the store already has its own FAQ section).';
        }

        return array_values(array_unique($notes));
    }

    private function clip(string $message): string
    {
        return mb_strlen($message) > self::PUBLISH_NOTE_MAX
            ? rtrim(mb_substr($message, 0, self::PUBLISH_NOTE_MAX - 1)) . '…'
            : $message;
    }

    /**
     * نسخه‌ی محلی را به‌روز می‌کند تا تا sync بعدی نقص تکراری نشان داده نشود.
     * رفتار پلاگین را بازسازی می‌کند:
     *  - branded: توضیحات جایگزین می‌شود.
     *  - themed : محتوای فروشگاه دست‌نخورده می‌ماند و متن سئو داخل <!--youtix-desc--> بعد از آن اضافه می‌شود.
     *  - بلوک FAQ در هر دو حالت آخر توضیحات است و فقط با faq جدید عوض می‌شود.
     */
    private function syncLocalProduct(SiteProduct $product, array $payload, string $styleMode = 'branded'): void
    {
        $changes = [];

        if (isset($payload['short_description'])) {
            $changes['short_description'] = $payload['short_description'];
        }

        if (isset($payload['description']) || isset($payload['faq'])) {
            $current = (string) $product->description;
            $store = $this->stripYoutixBlocks($current);

            if ($styleMode === 'themed') {
                $descBlock = isset($payload['description'])
                    ? (string) $payload['description']
                    : $this->markedBlock($current, self::DESC_START, self::DESC_END);

                $base = trim($store . ($descBlock !== '' ? "\n" . self::DESC_START . $descBlock . self::DESC_END : ''));
            } else {
                $base = isset($payload['description']) ? (string) $payload['description'] : $store;
            }

            if (isset($payload['faq'])) {
                $faqHtml = ProductContentSanitizer::faqHtml($this->pluginFaq($payload['faq']));
            } else {
                // FAQ فعلی با جایگزینی توضیحات از بین نمی‌رود (پلاگین هم همین کار را می‌کند)
                $faqHtml = $this->existingFaqBlock($current);
            }

            $changes['description'] = ProductContentSanitizer::withFaq($base, $faqHtml);
        }

        if (isset($payload['attributes']) || isset($payload['tags']) || isset($payload['image_alts'])) {
            $raw = $product->raw_data;
            $raw = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;

            if (isset($payload['attributes'])) {
                $raw['attributes'] = (array) ($raw['attributes'] ?? []);

                $known = [];
                foreach ($raw['attributes'] as $existing) {
                    $known[$this->normalizeLabel((string) ($existing['name'] ?? ''))] = true;
                }

                foreach ($payload['attributes'] as $a) {
                    $key = $this->normalizeLabel((string) $a['name']);

                    if (isset($known[$key])) {
                        continue;
                    }

                    $known[$key] = true;
                    $raw['attributes'][] = ['name' => $a['name'], 'options' => [$a['value']]];
                }
            }

            if (isset($payload['tags'])) {
                $raw['tags'] = (array) ($raw['tags'] ?? []);

                $seen = [];
                foreach ($raw['tags'] as $t) {
                    $seen[PageProductGapDetectionService::norm((string) (is_array($t) ? ($t['name'] ?? '') : $t))] = true;
                }

                foreach ($payload['tags'] as $tag) {
                    $key = PageProductGapDetectionService::norm((string) $tag);

                    if ($key !== '' && ! isset($seen[$key])) {
                        $seen[$key] = true;
                        $raw['tags'][] = ['name' => $tag];
                    }
                }
            }

            if (isset($payload['image_alts']) && is_array($raw['images'] ?? null)) {
                $alts = array_column($payload['image_alts'], 'alt', 'id');

                foreach ($raw['images'] as $i => $img) {
                    $id = is_array($img) ? (int) ($img['id'] ?? 0) : 0;

                    if ($id > 0 && isset($alts[$id]) && trim((string) ($img['alt'] ?? '')) === '') {
                        $raw['images'][$i]['alt'] = $alts[$id];
                    }
                }
            }

            $changes['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if ($changes !== []) {
            // toBase: بدون cast مدل (مثل بقیه‌ی سرویس‌های محصول)
            SiteProduct::where('id', $product->id)->toBase()->update($changes + ['updated_at' => now()]);
        }
    }

    /**
     * FAQ را همان‌طور که پلاگین ذخیره می‌کند برمی‌گرداند: سؤال بدون «؟» یا «?» در پایان، «؟» می‌گیرد.
     *
     * @param  array<int, mixed>  $faq
     * @return list<array{question: string, answer: string}>
     */
    private function pluginFaq(array $faq): array
    {
        $out = [];

        foreach ($faq as $row) {
            if (! is_array($row)) {
                continue;
            }

            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            if (! preg_match('/[?؟]$/u', $question)) {
                $question .= '؟';
            }

            $out[] = ['question' => $question, 'answer' => $answer];
        }

        return $out;
    }

    private function markedPattern(string $start, string $end): string
    {
        return '/' . preg_quote($start, '/') . '(.*?)' . preg_quote($end, '/') . '/su';
    }

    private function markedBlock(string $html, string $start, string $end): string
    {
        return preg_match($this->markedPattern($start, $end), $html, $m) ? trim($m[1]) : '';
    }

    /** هر دو بلوک Youtix (متن سئو و FAQ) را برمی‌دارد و محتوای خود فروشگاه می‌ماند. */
    private function stripYoutixBlocks(string $html): string
    {
        foreach ([[self::DESC_START, self::DESC_END], [ProductContentSanitizer::FAQ_START, ProductContentSanitizer::FAQ_END]] as [$start, $end]) {
            $html = (string) preg_replace($this->markedPattern($start, $end), '', $html);
        }

        return trim($html);
    }

    private function existingFaqBlock(string $descriptionHtml): string
    {
        return $this->markedBlock($descriptionHtml, ProductContentSanitizer::FAQ_START, ProductContentSanitizer::FAQ_END);
    }

    private function productRoute(string $group, string $default): string
    {
        $value = trim((string) config("wordpress_agent.{$group}.products", ''));

        if ($value === '') {
            Log::warning('wp_page_product_seo.route_config_missing', ['key' => "wordpress_agent.{$group}.products", 'using' => $default]);
            $value = $default;
        }

        $value = rtrim($value, '/');

        return $group === 'signing_paths' ? ltrim($value, '/') : '/' . ltrim($value, '/');
    }

    private function normalizeLabel(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
    }

    /* ------------------------------------------------------------------ */
    /*  خطا / وضعیت / لاگ                                                    */
    /* ------------------------------------------------------------------ */

    private function errorMessage(array $result): string
    {
        $code = $this->wpErrorCode($result);

        if ($code !== null && isset(self::WP_ERROR_MESSAGES[$code])) {
            $message = self::WP_ERROR_MESSAGES[$code];

            if ($code === 'rest_invalid_param') {
                $params = $this->invalidParams($result);

                if ($params !== []) {
                    $message .= ' (' . implode('، ', array_map(static fn (string $p): string => self::FIELD_LABELS[$p] ?? $p, $params)) . ')';
                }
            }

            return $message;
        }

        if (($result['status_code'] ?? null) === 404) {
            return 'The product was not found in WooCommerce, or the Sevof plugin is outdated (install the latest Sevof Connector).';
        }

        return (string) ($result['message'] ?? 'Sending to WordPress failed.');
    }

    /**
     * نام فیلدهای ردشده در خطای rest_invalid_param وردپرس (data.params).
     *
     * @return list<string>
     */
    private function invalidParams(array $result): array
    {
        $candidates = [$result];

        foreach (self::RESPONSE_KEYS as $key) {
            if (is_array($result[$key] ?? null)) {
                $candidates[] = $result[$key];
            }
        }

        foreach ($candidates as $body) {
            $params = is_array($body['data'] ?? null) ? ($body['data']['params'] ?? null) : null;
            $params ??= $body['params'] ?? null;

            if (is_array($params) && $params !== []) {
                return array_values(array_filter(array_map('strval', array_keys($params)), static fn (string $p): bool => $p !== ''));
            }
        }

        return [];
    }

    private function wpErrorCode(array $result): ?string
    {
        foreach (self::RESPONSE_KEYS as $key) {
            $code = is_array($result[$key] ?? null) ? ($result[$key]['code'] ?? null) : null;

            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return is_string($result['code'] ?? null) ? $result['code'] : null;
    }

    private function logSeoResult(PageProductSeoOptimization $optimization, array $result): void
    {
        $ok = (bool) ($result['ok'] ?? false);

        Log::log($ok ? 'info' : 'warning', 'wp_page_product_seo.' . self::ACTION_UPDATE, [
            'optimization_id' => $optimization->id,
            'site_id' => $optimization->site_id,
            'ok' => $ok,
            'status_code' => $result['status_code'] ?? null,
            'wp_code' => $ok ? null : $this->wpErrorCode($result),
            'error' => $ok ? null : ($result['message'] ?? null),
            'updated_fields' => $ok ? $this->updatedFields($result) : null,
            'preserved_fields' => $ok ? $this->stringList($this->responseField($result, 'preserved_fields')) : null,
            'style_mode' => $ok ? $this->responseField($result, 'style_mode') : null,
        ]);
    }

    private function failProductSeo(PageProductSeoOptimization $optimization, string $message): array
    {
        $result = ['ok' => false, 'message' => $message];

        $this->logSeoResult($optimization, $result);

        $optimization->update([
            'status' => $optimization->status === PageProductSeoOptimization::STATUS_APPROVED
                ? PageProductSeoOptimization::STATUS_READY
                : $optimization->status,
            'wp_sync_status' => 'failed',
            'publish_error' => $this->clip($message),
        ]);

        return $result;
    }
}
