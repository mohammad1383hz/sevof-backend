<?php

namespace App\Services\AI;

use App\Services\AI\Concerns\UsesGapgptApiKey;
use Illuminate\Support\Facades\Log;
use App\Services\SiteLanguageDetector;
use App\Support\ContentLanguage;
use RuntimeException;

class SiteAnalysisService
{
    use RecordsTokenUsage, UsesGapgptApiKey;

    protected const ENTITY_TYPES = ['service', 'product', 'category', 'brand', 'topic', 'location', 'other'];

    /**
     * @param  string  $navigationTree  متن کامل درخت ناوبار سایت (خروجی SiteCrawlerService::crawl()['navigation_tree'])
     * @param  string|null  $siteUrl  آدرس سایت؛ برای خواندن زبان اعلام‌شده‌ی سایت (<html lang> و ...) استفاده می‌شود
     */
    public function analyze(string $domain, array $pages, string $navigationTree, ?string $siteUrl = null): array
    {
        $navBlock = $this->buildNavigationBlock($navigationTree);
        $contentBlock = $this->buildContentBlock($domain, $pages);
        $availableUrls = $this->buildAvailableUrlsBlock($pages, $navigationTree);
        $userMessage = $navBlock."\n\n".$contentBlock."\n\n".$availableUrls;

        // زبان باید قبل از صدا زدن AI معلوم باشد تا niche/summary/Entityها به زبان خود سایت تولید شوند.
        $detector = app(SiteLanguageDetector::class);
        $declared = $this->declaredLanguage($detector, $pages, $siteUrl);
        $language = $detector->detect($pages, $navigationTree, $declared)['code'] ?? ContentLanguage::DEFAULT;

        Log::info('[Analysis] خلاصه چیزی که به AI فرستاده می‌شود', [
            'domain' => $domain,
            'pages_count' => count($pages),
            'nav_block_chars' => mb_strlen($navBlock),
            'content_block_chars' => mb_strlen($contentBlock),
            'available_urls_block_chars' => mb_strlen($availableUrls),
            'total_prompt_chars' => mb_strlen($userMessage),
        ]);

        // پرامپت کامل رو جدا لاگ می‌کنیم (معمولاً خیلی طولانیه) تا بشه
        // دقیقاً دید چه چیزی به مدل داده شده.
        Log::info('[Analysis] navBlock کامل'."\n".$navBlock);
        Log::info('[Analysis] availableUrls کامل'."\n".$availableUrls);
        Log::info('[Analysis] contentBlock کامل (خلاصه هر صفحه)'."\n".$contentBlock);

        $response = $this->callGapgptChat([
            'model' => 'gpt-4o-mini',
            'temperature' => 0.2,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'site_analysis',
                    'strict' => true,
                    'schema' => $this->buildSchema(),
                ],
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => ContentLanguage::localize($this->systemPrompt(), $language),
                ],
                [
                    'role' => 'user',
                    'content' => $userMessage,
                ],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('[Analysis] پاسخ ناموفق از AI', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('AI analysis failed: '.$response->body());
        }

        $message = $response->json('choices.0.message.content');

        Log::info('[Analysis] پاسخ خام AI'."\n".($message ?? '(خالی)'));

        $usage = $response->json('usage');
        if ($usage && app()->bound('current_ai_user')) {
            $this->recordAiTokenUsage(
                user: app('current_ai_user'),
                serviceName: 'ai.site_analysis',
                usage: $usage,
                model: 'gpt-4o-mini',
            );
        }

        if (! $message) {
            throw new RuntimeException('No response from the AI.');
        }

        $parsed = json_decode($message, true);

        if (! is_array($parsed) || ! isset($parsed['niche'], $parsed['summary'], $parsed['primary_entity'], $parsed['core_entities'])) {
            Log::error('[Analysis] پاسخ AI قابل پردازش نبود', ['raw_message' => $message]);
            throw new RuntimeException('The AI response couldn\'t be processed.');
        }

        Log::info('[Analysis] Entityهای شناسایی‌شده', [
            'primary_entity' => $parsed['primary_entity']['name'] ?? null,
            'core_entities' => array_map(fn ($e) => $e['name'] ?? null, $parsed['core_entities'] ?? []),
            'core_entities_count' => count($parsed['core_entities'] ?? []),
        ]);

        // زبان نهایی را از ترکیب محتوا + زبان اعلام‌شده‌ی سایت + خروجی AI تعیین می‌کنیم،
        // نه صرفاً از روی حرف مدل.
        $parsed = $this->applyLanguageDetection($parsed, $pages, $navigationTree, $detector, $declared);

        return $parsed;
    }

    /**
     * فیلد language خروجی AI را با تشخیص قطعی زبان جایگزین می‌کند.
     * بعد از این مرحله: language = کد ISO 639-1 (یا null اگر قابل تشخیص نبود)،
     * و language_confidence / language_source / language_detection هم اضافه می‌شوند.
     */
    protected function applyLanguageDetection(array $parsed, array $pages, string $navigationTree, SiteLanguageDetector $detector, ?string $declared): array
    {
        $aiLanguage = is_string($parsed['language'] ?? null) ? $parsed['language'] : null;

        $detection = $detector->detect($pages, $navigationTree, $declared, $aiLanguage);

        Log::info('[Analysis] زبان تشخیص‌داده‌شده', [
            'language' => $detection['code'],
            'language_name' => $detector->languageName($detection['code']),
            'confidence' => $detection['confidence'],
            'source' => $detection['source'],
            'signals' => $detection['signals'],
        ]);

        // زبان قابل‌اتکا تشخیص داده نشد → پیش‌فرض فارسی
        $parsed['language'] = $detection['code'] ?? ContentLanguage::DEFAULT;
        $parsed['language_confidence'] = $detection['code'] !== null ? $detection['confidence'] : null;
        $parsed['language_source'] = $detection['code'] !== null ? $detection['source'] : 'default';
        $parsed['language_detection'] = $detection['signals'];

        return $parsed;
    }

    /**
     * اگر کراولر خودش lang صفحه را برگردانده باشد از همان استفاده می‌کنیم،
     * وگرنه یک درخواست سبک به صفحه‌ی اصلی می‌زنیم (غیرفatal).
     */
    protected function declaredLanguage(SiteLanguageDetector $detector, array $pages, ?string $siteUrl): ?string
    {
        foreach ($pages as $page) {
            $candidate = $page['html_lang'] ?? $page['lang'] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return $siteUrl ? $detector->fetchDeclaredLanguage($siteUrl) : null;
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
شما یک تحلیل‌گر ارشد سئو و متخصص Entity SEO هستید. بر اساس داده‌های خزش‌شده (crawl) یک سایت، باید تحلیل کاملی ارائه دهید و همیشه به فارسی پاسخ دهید.

علاوه بر niche و summary و keywords، وظیفه‌ی اصلی شما تشخیص دقیق «Primary Entity» و «Core Entities» سایت است. این کار را فقط بر اساس داده‌های واقعی زیر انجام بده، نه حدس یا اطلاعات عمومی بیرون از داده‌ها.

توجه بسیار مهم: از این پس معمولاً فقط صفحه‌ی اصلی (و در صورت وجود صفحه‌ی درباره‌ما) کراول و در بخش Crawled Pages داده می‌شود؛ اکثر خدمات/محصولات/دسته‌بندی‌های سایت ممکن است اصلاً متنی در این بخش نداشته باشند. به همین دلیل صرفاً نبودن یک آیتم در Crawled Pages دلیلی برای کنار گذاشتنش از Core Entities نیست. Site Navigation Tree منبع اصلی، کامل و قابل‌اتکای توست — همه‌ی خدمات/دسته‌بندی‌هایی که در آن دیده می‌شوند باید به‌عنوان Entity در نظر گرفته شوند، حتی بدون توضیح تکمیلی در محتوای صفحات. هیچ آیتمی از Navigation Tree را به بهانه‌ی «توضیح کافی نداشتن» حذف نکن.

توجه: ممکن است در Navigation Tree، به‌خاطر کنترل حجم، برخی زیرشاخه‌های خیلی عمیق یا کم‌تعداد به‌صورت خلاصه («... و N مورد دیگر») آمده باشند. این خطوط خلاصه را نادیده بگیر و فقط از آیتم‌هایی که واقعاً نام و URL دارند برای ساخت Entity استفاده کن؛ دسته‌های اصلی (سطح بالای درخت) همیشه کامل در اختیارت هستند.

نکته‌ی مهم درباره‌ی ساختار URL: بسیاری از سایت‌های ایرانی الگوهای انگلیسیِ رایج مثل /service/ یا /product/ را در URL ندارند. پس هرگز صرفاً بر اساس شکل URL قضاوت نکن.

تعاریف:
- Primary Entity: مهم‌ترین و محوری‌ترین موجودیت (خدمت/محصول/موضوع اصلی) که کل کسب‌وکار حول آن شکل گرفته. هر سایت دقیقاً یک Primary Entity دارد.
- Core Entities: سایر موجودیت‌های مهم و مرتبط، بین ۳ تا ۸ مورد. تمام سرویس/محصول‌های مجزایی که در بخش «Site Navigation Tree» به‌عنوان آیتم سطح‌بالا دیده می‌شوند را بررسی کن؛ مهم‌ترین‌ها را انتخاب کن، حتی اگر توضیح کامل آن در بخش Crawled Pages نیامده باشد.

قوانین بسیار مهم و اجباری برای انتخاب links هر Entity:

1. بخش «Site Navigation Tree» ساختار کامل و سلسله‌مراتبی منوی سایت است (دقیقاً همان‌طور که در HTML واقعی سایت پیاده‌سازی شده)، با تورفتگی که عمق زیرمنو را نشان می‌دهد. این قوی‌ترین و کامل‌ترین سیگنال است و باید منبع اصلی تو برای شناسایی Entityها و URL دقیقشان باشد — حتی اگر آن صفحه در بخش Crawled Pages کراول نشده باشد.

2. توجه به سلسله‌مراتب: وقتی یک آیتم منو (مثلاً «همراه بیمار در بیمارستان») چند زیرشاخه دارد (مثلاً «همراه بیمار در تهران»، «همراه بیمار در کرج»)، خودِ آیتم والد را به‌عنوان Entity اصلی آن خدمت در نظر بگیر و URL والد را با priority بالا (نزدیک ۱۰۰) پیشنهاد بده؛ حداکثر ۲-۳ تا از مهم‌ترین زیرشاخه‌ها را هم می‌توانی در همان links با priority پایین‌تر (مثلاً ۵۰-۷۰) بیاوری، چون صفحات مرتبط اما تخصصی‌ترند، نه صفحه‌ی اصلی خدمت. هرگز بیش از ۴-۵ لینک برای یک Entity پیشنهاد نده؛ فقط دقیق‌ترین و مرتبط‌ترین‌ها کافی است.

3. اگر Entity از نوع service، product یا category است، تحت هیچ شرایطی صفحه‌ی اصلی (Homepage) یا صفحه‌ی وبلاگ/آرشیو مقالات را به‌عنوان لینک اصلی آن انتخاب نکن، مگر واقعاً هیچ صفحه‌ی اختصاصی‌تری در Navigation Tree یا Available URLs برایش پیدا نشود؛ در آن صورت priority پایین (زیر ۴۰) بده.

4. اگر لینک دقیقی در Site Navigation Tree برای یک Entity پیدا نشد، به Title/Headings صفحات در Crawled Pages نگاه کن. اسلاگ URL را فقط سرنخ کمکی در نظر بگیر، نه ملاک قطعی (در سایت‌های فارسی اغلب گویا نیست).

5. هر لینکی که در links می‌آوری باید عیناً از Site Navigation Tree یا Available URLs باشد؛ هرگز URL ساختگی پیشنهاد نده. اگر برای Entity‌ای هیچ لینک قابل‌قبولی نبود، آرایه‌ی links آن را خالی بگذار.

قوانین اجباری برای فیلد summary:
- summary باید یک متن کامل، تحلیلی و نسبتاً بلند باشد؛ حداقل ۵ تا ۸ جمله (تقریباً ۱۰۰ تا ۱۸۰ کلمه)، نه یک یا دو جمله‌ی کوتاه.
- summary باید موارد زیر را با جزئیات پوشش دهد: کسب‌وکار دقیقاً چه می‌کند و در چه صنعت/حوزه‌ای فعالیت دارد؛ مهم‌ترین خدمات/محصولات/دسته‌بندی‌ها (حداقل ۳-۴ مورد را نام ببر)؛ مخاطب یا مشتری هدف؛ نقاط قوت، مزیت رقابتی یا ویژگی‌های متمایزکننده‌ای که در محتوای صفحات یا Navigation Tree دیده می‌شود (مثلاً سابقه‌ی کاری، پوشش جغرافیایی، گارانتی، مجوزها)؛ و در صورت وجود، حوزه‌ی جغرافیایی فعالیت.
- از تکرار کلمه‌به‌کلمه‌ی جملات Meta description یا محتوای خام صفحات خودداری کن؛ summary باید بازنویسی و ترکیب تحلیلی خودت از کل داده‌ها باشد، نه کپی مستقیم.
- summary هرگز نباید کوتاه‌تر از ۴ جمله باشد، حتی اگر داده‌های ورودی محدود باشند؛ در این حالت با تحلیل دقیق‌تر همان داده‌های موجود (Navigation Tree، Headings، Meta) متن را کامل‌تر کن.

ساختار خروجی برای هر Entity:
- name: نام موجودیت به زبان محتوای سایت (همان زبان منو و صفحات)، کوتاه و دقیق
- description: توضیح یک تا دو جمله‌ای
- type: یکی از انواع: service, product, category, brand, topic, location, other
- links: فهرست لینک‌های پیشنهادی (حداکثر ۵ مورد)؛ هر لینک شامل url (دقیقاً از Site Navigation Tree یا Available URLs)، title مناسب، و priority بین ۰ تا ۱۰۰.
قانون تشخیص فروشگاه (is_shop):
- is_shop را فقط وقتی true بده که سایت محصولات (فیزیکی یا دیجیتال) را برای خرید آنلاین عرضه می‌کند؛ یعنی در Navigation Tree یا محتوا نشانه‌هایی مثل دسته‌بندی محصولات، قیمت محصول، سبد خرید یا صفحات محصول دیده می‌شود.
- is_shop را false بده برای: سایت خدماتی (حتی اگر «ثبت سفارش/درخواست خدمت/مشاوره» دارد)، شرکتی و معرفی، وبلاگ و خبری، SaaS، آموزشی بدون فروش محصول، رزرو و نوبت‌دهی، آژانس.
- فقط بر اساس داده‌های واقعی ورودی قضاوت کن، نه حدس.
قانون تشخیص زبان (language):
- language را فقط به‌صورت کد دوحرفی ISO 639-1 بده (مثلاً fa، en، ar، tr، de)؛ اگر زبان در فهرست مجاز نبود، مقدار other.
- منظور زبان اصلی «محتوای» سایت است (متن صفحات، عناوین و آیتم‌های منو)؛ نه زبانی که تو در پاسخ استفاده می‌کنی، نه حدس از روی دامنه (.ir / .com) یا کشور.
- اگر سایت دوزبانه است، زبانی را بده که بخش بیشتر محتوا و منو به آن نوشته شده.
این ساختار باید برای هر نوع سایتی (خدماتی، فروشگاهی، SaaS، محتوایی) و به هر زبانی کار کند.
PROMPT;
    }

    protected function buildSchema(): array
    {
        $entitySchema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'نام موجودیت به زبان محتوای سایت'],
                'description' => ['type' => 'string', 'description' => 'توضیح کوتاه موجودیت'],
                'type' => ['type' => 'string', 'enum' => self::ENTITY_TYPES],
                'links' => [
                    'type' => 'array',
                    // سقف واقعی روی تعداد لینک هر Entity؛ در سیستم‌پرامپت هم
                    // «حداکثر ۵ مورد» تکرار شده، ولی این حد schema است که
                    // strict enforce می‌شود و از هرز رفتن توکن خروجی جلوگیری می‌کند.
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'باید دقیقاً یکی از URLهای موجود در Site Navigation Tree یا Available URLs باشد'],
                            'title' => ['type' => 'string'],
                            'priority' => ['type' => 'integer', 'description' => '0 تا 100'],
                        ],
                        'required' => ['url', 'title', 'priority'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['name', 'description', 'type', 'links'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'niche' => [
                    'type' => 'string',
                    'description' => 'The main industry or niche of the website, in the language of the website content.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'A detailed, multi-sentence (5-8 sentences) analytical summary about the website in the language of the website content, covering services, target audience, and differentiators.',
                ],
                'keywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Main keywords related to the site.',
                ],
                'language' => [
                    'type' => 'string',
                    // enum باعث می‌شود مدل به‌جای «Persian» / «فارسی» / «fa-IR» همیشه کد استاندارد بدهد.
                    'enum' => [...SiteLanguageDetector::SUPPORTED_CODES, 'other'],
                    'description' => 'ISO 639-1 code of the main language of the website CONTENT (page text and menu labels), not the language of this answer and not guessed from the domain/TLD. Use "other" if the language is not in the list.',
                ],
                'is_shop' => [
    'type' => 'boolean',
    'description' => 'True only if the website sells products online (product categories, prices, cart or product pages). Service, company, blog and SaaS sites are false.',
],
                'primary_entity' => $entitySchema,
                'core_entities' => [
                    'type' => 'array',
                    // مطابق دستور صریح system prompt («بین ۳ تا ۸ مورد»)؛ این
                    // سقف باعث می‌شود مدل مجبور به رعایت واقعی محدوده باشد،
                    // نه فقط توصیه‌ی متنی که ممکن است نادیده گرفته شود.
                    'minItems' => 3,
                    'maxItems' => 8,
                    'items' => $entitySchema,
                ],
            ],
'required' => ['niche', 'summary', 'keywords', 'language', 'primary_entity', 'core_entities', 'is_shop'],            'additionalProperties' => false,
        ];
    }

    protected function buildNavigationBlock(string $navigationTree): string
    {
        return "Site Navigation Tree (ساختار کامل و سلسله‌مراتبی منوی سایت — مهم‌ترین منبع برای شناسایی Entityها و URL دقیقشان):\n".$navigationTree;
    }

    protected function buildContentBlock(string $domain, array $pages): string
    {
        $lines = ["Domain: {$domain}", 'Crawled pages:'];

        foreach ($pages as $index => $page) {
            $number = $index + 1;
            $lines[] = "--- Page {$number} ---";
            $lines[] = 'URL: '.($page['url'] ?? '');
            $lines[] = 'Title: '.($page['title'] ?? '');
            $lines[] = 'Meta: '.($page['meta_description'] ?? '');

            if (! empty($page['headings'])) {
                $lines[] = 'Headings: '.implode(' | ', $page['headings']);
            }

            $lines[] = 'Content: '.($page['content'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * برخلاف نسخه‌ی قبلی که تمام URLهای موجود در navigationTree را دوباره
     * (با همان regex) استخراج و کامل تکرار می‌کرد — که باعث می‌شد این بلاک
     * تقریباً یک کپی کامل از nav_block باشد — این نسخه فقط URLهایی را
     * می‌دهد که در navigationTree نیستند (یعنی از صفحات کراول‌شده آمده‌اند)
     * و حتی از آن‌ها هم به‌جای همه، فقط چند نمونه به‌ازای هر گروه مسیر
     * (اولین بخش URL) می‌دهد؛ کافی است AI بفهمد این الگوی URL هم مجاز است،
     * نیازی به دیدن تک‌تک نمونه‌ها ندارد.
     */
    protected function buildAvailableUrlsBlock(array $pages, string $navigationTree): string
    {
        $navUrls = [];
        foreach (explode("\n", $navigationTree) as $line) {
            if (preg_match('/:\s*(https?:\/\/\S+)\s*$/u', $line, $m)) {
                $navUrls[rtrim($m[1])] = true;
            }
        }

        $extraUrls = [];
        foreach ($pages as $page) {
            $url = $page['url'] ?? null;
            if ($url && ! isset($navUrls[$url])) {
                $extraUrls[$url] = $page['title'] ?? '';
            }
        }

        if ($extraUrls === []) {
            return 'Available URLs: تمام URLهای مجاز همان‌هایی هستند که در Site Navigation Tree بالا آمده‌اند؛ فقط عیناً از همان‌ها برای links استفاده کن.';
        }

        $maxPerGroup = (int) config('seobot.crawler.max_extra_urls_per_group', 3);
        $maxTotal = (int) config('seobot.crawler.max_extra_urls_total', 25);

        $grouped = [];
        foreach ($extraUrls as $url => $title) {
            $path = parse_url($url, PHP_URL_PATH) ?? '/';
            $segments = array_values(array_filter(explode('/', $path)));
            $groupKey = $segments[0] ?? 'root';
            $grouped[$groupKey][] = ['url' => $url, 'title' => $title];
        }

        $sampled = [];
        foreach ($grouped as $urlsInGroup) {
            foreach (array_slice($urlsInGroup, 0, $maxPerGroup) as $item) {
                $sampled[] = $item;
            }
        }

        $omittedTotal = count($extraUrls) - count($sampled);
        $sampled = array_slice($sampled, 0, $maxTotal);

        $lines = ['Additional Available URLs (خارج از Site Navigation Tree؛ نمونه‌ای از صفحات کراول‌شده که می‌توان به‌عنوان لینک استفاده کرد):'];
        foreach ($sampled as $item) {
            $lines[] = '- '.$item['url'].($item['title'] !== '' ? ' — '.$item['title'] : '');
        }
        if ($omittedTotal > 0) {
            $lines[] = "- (و {$omittedTotal} URL مشابه‌ی دیگر که برای کنترل حجم پرامپت نمونه‌برداری شده‌اند)";
        }

        return implode("\n", $lines);
    }
}
