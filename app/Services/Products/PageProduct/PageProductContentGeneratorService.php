<?php

namespace App\Services\Products\PageProduct;

use App\Models\User;
use App\Services\AI\Concerns\UsesGapgptApiKey;
use App\Services\TokenUsageService;
use App\Support\ContentLanguage;
use App\Support\PersianWritingSkill;
use Illuminate\Support\Str;

/**
 * تولید محتوا: یک فراخوانی AI برای همه‌ی فیلدهای ناقص + کنترل کیفیت سبک با کد.
 *
 * فیلدهای قابل تولید: short_description, description, attributes, seo_title,
 * meta_description, focus_keyword, tags, faq, image_alts (فقط هر چه در $missing باشد).
 *
 * بدون سرچ و بدون تأیید بیرونی: مدل بر اساس اطلاعات خود محصول می‌نویسد و
 * خروجی‌اش (به‌جز موارد خالی/تکراری/نامعتبر) مستقیم پذیرفته می‌شود. مراقبت از درستی
 * اطلاعات از طریق پرامپت + هشدار unproven_numbers روی کل متن تولیدشده انجام می‌شود.
 *
 * کلیدهای اختیاری config (همه default دارند):
 *   page_product_seo.ai.generator_model
 *   page_product_seo.ai.generator_max_tokens   (4500)
 *   page_product_seo.limits.seo_title          (70)
 *   page_product_seo.limits.meta_description   (160)
 *   page_product_seo.limits.tags_max           (7)
 *   page_product_seo.limits.tag_max_length     (40)
 *   page_product_seo.limits.faq_max            (5)
 *   page_product_seo.limits.alt_max_length     (120)
 */
class PageProductContentGeneratorService
{
    use UsesGapgptApiKey;

    private const SYSTEM = <<<'TXT'
تو نویسنده‌ی سئوی محصولات فروشگاه اینترنتی هستی. فقط یک JSON معتبر برگردان.
بر اساس اطلاعات خود محصول (نام، برند، مدل، دسته‌بندی و توضیحات/مشخصات فعلی) بنویس. نیازی به منبع بیرونی نیست.

قوانین کلی:
- فقط فیلدهای موجود در fields_to_fill را تولید کن؛ بقیه را در خروجی نیاور.
- مشخصات موجود و صحیح محصول را حفظ کن و دوباره ننویس؛ در attributes فقط مشخصات جدید بیاور.
- مشخصات را متناسب با نوع کالا انتخاب کن (برای صندلی و گوشی یک قالب یکسان نده).
- متن طبیعی، مفید و مناسب سئو بنویس؛ کلمه‌ی کلیدی را بیش از حد تکرار نکن.
- ویژگی‌های کلی و توصیفی (کاربرد، سبک، مناسب چه فضا، فصل یا کسی) را آزادانه و روان بنویس؛ در این بخش سخت‌گیری لازم نیست.
- برای مشخصات فنی، اگر محصول برند و مدل مشخص دارد، می‌توانی از دانش خودت درباره‌ی همان محصول استفاده کنی، ولی فقط چیزهایی را بنویس که از درستی‌شان مطمئنی.
- اگر از یک عدد، ظرفیت، وزن، ابعاد، جنس یا ویژگی فنی مطمئن نیستی، حدس نزن و آن را ننویس. اطلاعات غلط از ننوشتن بدتر است. اگر آن مورد مهم است، در needs_seller_input بنویس.
- این قانون درباره‌ی همه‌ی فیلدها (توضیحات، سوال و جواب‌ها، alt تصاویر و برچسب‌ها) صدق می‌کند، نه فقط مشخصات.
- source هر مشخصه "seller_data" است (اگر از اطلاعات فعلی محصول آمده) یا "ai" (اگر از دانش خودت آمده).
- description را با HTML ساده (p, h2, ul, li) و short_description را در یک یا دو جمله بنویس.
- اگر product.category داده شده، نام دقیق همان دسته‌بندی را یک‌بار و به‌صورت طبیعی داخل یکی از پاراگراف‌های description (نه در تیتر) بیاور؛ سیستم خودش آن را به صفحه‌ی دسته لینک می‌کند. تگ a یا هیچ لینکی ننویس.
- seo_title حداکثر ۷۰ و meta_description حداکثر ۱۶۰ کاراکتر.

قوانین فیلدهای اضافی:
- focus_keyword: یک عبارت ۲ تا ۴ کلمه‌ای که کاربر واقعاً جست‌وجو می‌کند (معمولاً نوع کالا + برند یا مدل)، بدون علامت نگارشی. اگر current.focus_keyword وجود دارد همان را مبنا قرار بده. در seo_title، meta_description و پاراگراف اول description به‌صورت طبیعی بیاید.
- tags: ۳ تا ۷ برچسب کوتاه فارسی (هر کدام حداکثر ۳ کلمه) درباره‌ی نوع کالا، برند، دسته‌بندی یا کاربرد. بدون # و بدون تکرار برچسب‌های current.tags.
- faq: ۳ تا ۵ پرسش و پاسخ کوتاه که خریدار واقعاً می‌پرسد. فقط درباره‌ی چیزهایی که از اطلاعات محصول یا دانش مطمئنت معلوم است. درباره‌ی قیمت، موجودی، گارانتی، تخفیف، زمان یا هزینه‌ی ارسال سوال نساز. پاسخ متن ساده است (بدون HTML) و عدد فقط وقتی بیاید که مطمئنی.
- image_alts: برای هر آیتم images_missing_alt دقیقاً یک alt بنویس و همان id را برگردان. تصویر را نمی‌بینی؛ فقط بر اساس نام/برند/مدل محصول و (اگر معنی‌دار بود) نام فایل بنویس. رنگ، زاویه یا جزئیاتی که از ورودی معلوم نیست حدس نزن. هر alt کوتاه (حداکثر ۱۲۰ کاراکتر)، توصیفی، بدون شروع با «تصویر» یا «عکس» و تا حد ممکن با بقیه متفاوت باشد.

ساختار خروجی (فقط کلیدهای خواسته‌شده):
{
  "short_description": "...",
  "description": "...",
  "attributes": [{"name": "...", "value": "...", "source": "seller_data"}],
  "seo_title": "...",
  "meta_description": "...",
  "focus_keyword": "...",
  "tags": ["..."],
  "faq": [{"question": "...", "answer": "..."}],
  "image_alts": [{"id": 123, "alt": "..."}],
  "needs_seller_input": ["..."]
}
TXT;

    public function __construct(protected TokenUsageService $usage)
    {
    }

    /**
     * @param  list<string>  $missing
     */
    public function generate(array $snapshot, array $missing, User $user, string $language = ContentLanguage::DEFAULT): array
    {
        // alt فقط برای تصاویری که id دارند و alt ندارند (همان تعریف GapDetection)
        $images = in_array('image_alts', $missing, true) ? $this->imagesNeedingAlt($snapshot) : [];

        if (in_array('image_alts', $missing, true) && $images === []) {
            $missing = array_values(array_diff($missing, ['image_alts']));
        }

        // لینک دسته کار AI نیست: فقط نام و آدرس دسته برای تأیید کاربر برگردانده می‌شود و هنگام انتشار درج می‌شود
        // category: برای درج لینک در توضیحات تولیدی هنگام انتشار؛ category_link: فیلد قابل‌تأیید «لینک دسته» برای توضیحات فعلی
        $categoryLink = $snapshot['category_link'] ?? null;
        $extra = $categoryLink ? ['category' => $categoryLink] : [];

        // اگر توضیحات تولید می‌شود، لینک داخل همان است؛ فیلد جدای «لینک دسته» فقط برای توضیحات فعلی
        if ($categoryLink && in_array('category_link', $missing, true) && ! in_array('description', $missing, true)) {
            $extra['category_link'] = $categoryLink;
        }

        $missing = array_values(array_diff($missing, ['category_link']));

        if ($missing === []) {
            return $extra + ['needs_seller_input' => [], 'unproven_numbers' => []];
        }

        $filled = static fn ($v) => $v !== [] && $v !== null && $v !== '';

        $input = array_filter([
            'product' => array_filter([
                'name' => $snapshot['name'] ?? null,
                'category' => $snapshot['category'] ?? null,
                'brand' => $snapshot['brand'] ?? null,
                'model' => $snapshot['model'] ?? null,
            ]),
            'current' => array_filter([
                'short_description' => $snapshot['short_description'] ?? null,
                'description' => Str::limit((string) ($snapshot['description'] ?? ''), 1500, ''),
                'attributes' => $snapshot['attributes'] ?? [],
                'focus_keyword' => $snapshot['focus_keyword'] ?? null,
                'tags' => $this->tagNames($snapshot),
            ], $filled),
            'images_missing_alt' => $images,
            'fields_to_fill' => $missing,
        ], $filled);

        $model = (string) config('page_product_seo.ai.generator_model');

        $response = $this->callGapgptChat([
            'model' => $model,
            'max_tokens' => (int) config('page_product_seo.ai.generator_max_tokens', 4500),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => ContentLanguage::isPersian($language)
                    ? self::SYSTEM."\n\n".PersianWritingSkill::compact()
                    : ContentLanguage::localize(self::SYSTEM, $language)],
                ['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE)],
            ],
        ], 120);

        if (! $response->successful()) {
            throw new \RuntimeException((string) ($response->json('error.message') ?? 'AI service error.'));
        }

        // هزینه‌ی توکن (دلار → تومان → کریدیت) و کسر از اشتراک
        $this->usage->recordUsageFromResponse(
            $user,
            'page_product_seo_generate',
            (array) $response->json('usage'),
            $model,
            ['product_id' => $snapshot['id'] ?? null],
        );

        if ($response->json('choices.0.finish_reason') === 'length') {
            throw new \RuntimeException('The AI output was cut off (too long). Please try again.');
        }

        $raw = trim((string) $response->json('choices.0.message.content'));
        // بعضی مدل‌ها با وجود json_object، JSON را داخل ``` می‌گذارند
        $raw = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $raw));

        $out = json_decode($raw, true);

        if (! is_array($out)) {
            throw new \RuntimeException('The AI output was not valid JSON.');
        }

        $result = $extra + $this->validate($out, $snapshot, $missing, $images);

        // پیش‌نمایش همان چیزی را نشان می‌دهد که منتشر می‌شود: توضیحات با لینک به صفحه‌ی دسته
        if ($categoryLink && ! empty($result['description'])) {
            $result['description'] = ProductContentSanitizer::withCategoryLink(
                $result['description'], $categoryLink['name'], $categoryLink['url'], ContentLanguage::isPersian($language)
            );
        }

        return $result;
    }

    /**
     * کنترل کیفیت سبک: ساختار، طول، تکراری‌ها، پاک‌سازی HTML و هشدار عددهای اثبات‌نشده.
     * تأیید بیرونی نداریم.
     *
     * @param  list<array{id:int,position:int,file_name:string}>  $images
     */
    protected function validate(array $out, array $snapshot, array $missing, array $images = []): array
    {
        $result = [];

        foreach (['short_description', 'description', 'seo_title', 'meta_description'] as $field) {
            if (! in_array($field, $missing, true)) {
                continue;
            }

            $value = match ($field) {
                'seo_title' => $this->cut($this->plain($out[$field] ?? ''), $this->limit('seo_title', 70)),
                'meta_description' => $this->cut($this->plain($out[$field] ?? ''), $this->limit('meta_description', 160)),
                default => $this->cleanHtml($this->text($out[$field] ?? '')),
            };

            if ($value === '') {
                throw new \RuntimeException('Incomplete model output: '.$field);
            }

            $result[$field] = $value;
        }

        if (in_array('focus_keyword', $missing, true)) {
            $keyword = trim((string) preg_replace('/\s+/u', ' ', str_replace('#', '', $this->plain($out['focus_keyword'] ?? ''))));
            $words = preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($words === []) {
                throw new \RuntimeException('Incomplete model output: focus_keyword');
            }

            // عبارت کلیدی بلندتر از ۶ کلمه، عملاً جمله است
            $result['focus_keyword'] = implode(' ', array_slice($words, 0, 6));
        }

        if (in_array('attributes', $missing, true)) {
            $seen = array_map(
                fn ($a) => PageProductGapDetectionService::norm((string) ($a['name'] ?? '')),
                (array) ($snapshot['attributes'] ?? [])
            );
            $allowedNumbers = $this->numbersIn($this->allowedText($snapshot));

            $attributes = [];

            foreach ((array) ($out['attributes'] ?? []) as $a) {
                if (! is_array($a)) {
                    continue;
                }

                $name = $this->plain($a['name'] ?? '');
                $value = $this->plain($a['value'] ?? '');
                $source = $this->text($a['source'] ?? '');

                if ($name === '' || $value === '') {
                    continue;
                }

                // مشخصات قبلی (و تکراری‌های خود خروجی) بدون ضرورت اضافه نمی‌شوند
                $key = PageProductGapDetectionService::norm($name);

                if (in_array($key, $seen, true)) {
                    continue;
                }

                $seen[] = $key;

                // مدل ادعا کرده از اطلاعات فروشنده آمده، ولی عددش در آن اطلاعات نیست → ai
                if ($source === 'seller_data' && array_diff($this->numbersIn($value), $allowedNumbers) !== []) {
                    $source = 'ai';
                }

                $attributes[] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => $source === 'seller_data' ? 'seller_data' : 'ai',
                    'verified' => null, // تأیید بیرونی نداریم
                ];
            }

            $result['attributes'] = $attributes;
            $result['rejected_attributes'] = []; // برای سازگاری با فرانت؛ دیگر ردی در کار نیست
        }

        if (in_array('tags', $missing, true)) {
            $result['tags'] = $this->cleanTags((array) ($out['tags'] ?? []), $snapshot);
        }

        if (in_array('faq', $missing, true)) {
            $result['faq'] = $this->cleanFaq((array) ($out['faq'] ?? []));
        }

        if (in_array('image_alts', $missing, true)) {
            $result['image_alts'] = $this->cleanImageAlts((array) ($out['image_alts'] ?? []), $images);
        }

        $result['needs_seller_input'] = array_values(array_filter(array_map(
            fn ($v) => $this->plain($v),
            (array) ($out['needs_seller_input'] ?? [])
        )));

        // فقط هشدار برای پیش‌نمایش؛ جلوی انتشار را نمی‌گیرد
        $result['unproven_numbers'] = $this->unprovenNumbers($result, $snapshot);

        return $result;
    }

    /** فقط برچسب‌های جدید؛ ادغام با برچسب‌های فعلی محصول با لایه‌ی انتشار است. */
    protected function cleanTags(array $tags, array $snapshot): array
    {
        $seen = array_map(
            fn ($t) => PageProductGapDetectionService::norm($t),
            $this->tagNames($snapshot)
        );

        $max = $this->limit('tags_max', 7);
        $maxLength = $this->limit('tag_max_length', 40);

        $clean = [];

        foreach ($tags as $t) {
            $tag = trim(str_replace(['#', '_'], ['', ' '], $this->plain($t)));
            $tag = trim((string) preg_replace('/\s+/u', ' ', $tag));

            if ($tag === '' || mb_strlen($tag) > $maxLength || count(preg_split('/\s+/u', $tag)) > 4) {
                continue;
            }

            $key = PageProductGapDetectionService::norm($tag);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $clean[] = $tag;

            if (count($clean) >= $max) {
                break;
            }
        }

        return $clean;
    }

    /** @return list<array{question:string,answer:string}> */
    protected function cleanFaq(array $items): array
    {
        $max = $this->limit('faq_max', 5);
        $seen = [];
        $faq = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->plain($item['question'] ?? '');
            $answer = $this->plain($item['answer'] ?? '');

            if ($question === '' || $answer === '') {
                continue;
            }

            if (! preg_match('/[؟?]$/u', $question)) {
                $question .= '؟';
            }

            $key = PageProductGapDetectionService::norm($question);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $faq[] = ['question' => $question, 'answer' => $answer];

            if (count($faq) >= $max) {
                break;
            }
        }

        return $faq;
    }

    /**
     * فقط برای تصاویری که واقعاً به مدل داده شده‌اند (id دارند و alt ندارند).
     *
     * @param  list<array{id:int,position:int,file_name:string}>  $images
     * @return list<array{id:int,alt:string}>
     */
    protected function cleanImageAlts(array $items, array $images): array
    {
        $allowed = array_fill_keys(array_column($images, 'id'), true);
        $maxLength = $this->limit('alt_max_length', 120);

        $alts = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_numeric($item['id'] ?? null)) {
                continue;
            }

            $id = (int) $item['id'];

            // id ناشناخته (یا تصویری که قبلاً alt دارد) و تکرار id رد می‌شود
            if (! isset($allowed[$id]) || isset($alts[$id])) {
                continue;
            }

            $alt = $this->cut($this->plain($item['alt'] ?? ''), $maxLength);

            if ($alt === '') {
                continue;
            }

            $alts[$id] = ['id' => $id, 'alt' => $alt];
        }

        return array_values($alts);
    }

    /**
     * تصاویری که id وردپرس دارند و alt ندارند. تصویر فقط-URL را نمی‌شود alt داد.
     * اگر در GapDetection متد عمومی برای imagesMissingAlt هست، همین‌جا از همان استفاده کن
     * تا دو تعریف از «بدون alt» نداشته باشیم.
     *
     * @return list<array{id:int,position:int,file_name:string}>
     */
    protected function imagesNeedingAlt(array $snapshot): array
    {
        $out = [];

        foreach (array_values((array) ($snapshot['images'] ?? [])) as $i => $img) {
            if (! is_array($img) || ! is_numeric($img['id'] ?? null) || (int) $img['id'] <= 0) {
                continue;
            }

            if (trim((string) ($img['alt'] ?? '')) !== '') {
                continue;
            }

            $url = (string) ($img['url'] ?? $img['src'] ?? '');

            $out[] = [
                'id' => (int) $img['id'],
                'position' => $i + 1,
                'file_name' => urldecode(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)),
            ];
        }

        return $out;
    }

    /**
     * عددهایی که در متن تولیدشده آمده ولی در اطلاعات فعلی محصول نیست (هشدار، نه رد).
     * روی کل متن تولیدشده بررسی می‌شود، نه فقط توضیحات.
     *
     * @return list<string>
     */
    protected function unprovenNumbers(array $result, array $snapshot): array
    {
        $used = $this->numbersIn($this->textForNumbers($result));
        $allowed = $this->numbersIn($this->allowedText($snapshot));

        return array_values(array_diff($used, $allowed));
    }

    /** همه‌ی متن‌هایی که مدل نوشته و ممکن است عدد ادعایی داشته باشند. */
    protected function textForNumbers(array $result): string
    {
        $parts = [];

        foreach (['short_description', 'description'] as $field) {
            if (isset($result[$field])) {
                $parts[] = strip_tags((string) $result[$field]);
            }
        }

        foreach (['seo_title', 'meta_description', 'focus_keyword'] as $field) {
            if (isset($result[$field])) {
                $parts[] = (string) $result[$field];
            }
        }

        foreach ((array) ($result['attributes'] ?? []) as $a) {
            $parts[] = ($a['name'] ?? '').' '.($a['value'] ?? '');
        }

        foreach ((array) ($result['faq'] ?? []) as $f) {
            $parts[] = ($f['question'] ?? '').' '.($f['answer'] ?? '');
        }

        foreach ((array) ($result['image_alts'] ?? []) as $a) {
            $parts[] = (string) ($a['alt'] ?? '');
        }

        foreach ((array) ($result['tags'] ?? []) as $t) {
            $parts[] = (string) $t;
        }

        return implode("\n", $parts);
    }

    /** متن‌های معتبر محصول که عددهای داخلشان مجاز است. */
    protected function allowedText(array $snapshot): string
    {
        $parts = [
            $snapshot['seller_text'] ?? '',
            $snapshot['name'] ?? '',
            $snapshot['brand'] ?? '',
            $snapshot['model'] ?? '',
            $snapshot['category'] ?? '',
            $snapshot['short_description'] ?? '',
            strip_tags((string) ($snapshot['description'] ?? '')),
        ];

        foreach ((array) ($snapshot['attributes'] ?? []) as $a) {
            $parts[] = ($a['name'] ?? '').' '.($a['value'] ?? '');
        }

        foreach ($this->tagNames($snapshot) as $tag) {
            $parts[] = $tag;
        }

        foreach ((array) ($snapshot['images'] ?? []) as $img) {
            if (is_array($img)) {
                $parts[] = (string) ($img['alt'] ?? '');
            }
        }

        return implode("\n", array_filter($parts, 'is_scalar'));
    }

    /**
     * عددهای قابل‌توجه یک متن: ارقام فارسی/عربی به لاتین، جداکننده‌ی هزارگان حذف،
     * اعشار نگه داشته می‌شود. عدد تک‌رقمیِ صحیح نادیده گرفته می‌شود (مثل رفتار قبلی).
     *
     * @return list<string>
     */
    protected function numbersIn(string $text): array
    {
        $text = $this->latinDigits(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = (string) preg_replace('/(?<=\d)[,٬،](?=\d{3}(?!\d))/u', '', $text);
        $text = str_replace('٫', '.', $text);

        preg_match_all('/\d+(?:\.\d+)?/', $text, $m);

        $numbers = [];

        foreach ($m[0] as $n) {
            if (str_contains($n, '.')) {
                $n = rtrim(rtrim($n, '0'), '.');
            }

            if (strlen($n) >= 2 || str_contains($n, '.')) {
                $numbers[] = $n;
            }
        }

        return array_values(array_unique($numbers));
    }

    protected function latinDigits(string $v): string
    {
        return strtr($v, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** @return list<string> */
    protected function tagNames(array $snapshot): array
    {
        $names = [];

        foreach ((array) ($snapshot['tags'] ?? []) as $t) {
            $name = is_array($t) ? ($t['name'] ?? '') : $t;

            if (is_scalar($name) && trim((string) $name) !== '') {
                $names[] = trim((string) $name);
            }
        }

        return $names;
    }

    /** فقط تگ‌های ساده‌ی مجاز، بدون هیچ attribute (onclick، style، href و ...). */
    protected function cleanHtml(string $html): string
    {
        $html = strip_tags($html, '<p><h2><h3><ul><ol><li><strong><em><br>');

        return trim((string) preg_replace('/<(p|h2|h3|ul|ol|li|strong|em|br)\b[^>]*?(\/?)>/i', '<$1$2>', $html));
    }

    /** متن ساده: بدون تگ، entity دیکد شده، فاصله‌ها یکدست. */
    protected function plain(mixed $v): string
    {
        $s = is_scalar($v) ? (string) $v : '';
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    protected function text(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /** کوتاه‌کردن بر اساس تعداد کاراکتر، تا حد ممکن سر مرز کلمه. */
    protected function cut(string $v, int $max): string
    {
        if (mb_strlen($v) <= $max) {
            return $v;
        }

        $cut = mb_substr($v, 0, $max);
        $pos = mb_strrpos($cut, ' ');

        if ($pos !== false && $pos > $max * 0.6) {
            $cut = mb_substr($cut, 0, $pos);
        }

        return (string) preg_replace('/[\s\-–—،,.:;]+$/u', '', $cut);
    }

    protected function limit(string $key, int $default): int
    {
        return (int) config('page_product_seo.limits.'.$key, $default);
    }
}
