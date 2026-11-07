<?php

namespace App\Services\Crawler;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SiteCrawlerService
{
    /**
     * متن کامل درخت ناوبار سایت (فقط یک‌بار در طول کراول یک سایت پر می‌شود،
     * از اولین صفحه‌ای که منوی معتبر در آن پیدا شود — معمولاً صفحه‌ی اصلی).
     */
    protected ?string $navigationTree = null;

    /**
     * مجموعه‌ی raw href هایی که داخل تمام کانتینرهای ناوبار پیدا شده‌اند.
     */
    protected array $navHrefSet = [];

    /**
     * لینک‌های یوتیلیتی/غیر-Entity که هرگز نباید به‌عنوان Entity به AI
     * پیشنهاد شوند (سبد خرید، سفارش‌ها، شبکه‌ی اجتماعی، راهنمای خرید و...).
     * بر اساس بخشی از مسیر URL چک می‌شود، نه match دقیق، تا با/بدون
     * query string یا trailing slash هم کار کند.
     */
    protected const EXCLUDED_NAV_PATH_FRAGMENTS = [
        '/cart',
        '/checkout',
        '/my-account',
        '/wp-login',
        '/shopping-guide',
        'instagram.com',
        'facebook.com',
        'twitter.com',
        'x.com',
        'telegram.me',
        't.me',
        'wa.me',
        'whatsapp.com',
        'linkedin.com',
        'youtube.com',
    ];

    public function normalizeDomain(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidArgumentException('Domain can\'t be empty.');
        }

        if (! str_starts_with($input, 'http://') && ! str_starts_with($input, 'https://')) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);

        if (! isset($parts['host'])) {
            throw new InvalidArgumentException('The domain you entered is invalid.');
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host);

        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException('The domain you entered is invalid.');
        }

        return [
            'domain' => $host,
            'url' => 'https://'.$host,
        ];
    }

    /**
     * فقط صفحه‌ی اصلی و (در صورت وجود) صفحه‌ی درباره‌ما کراول می‌شود.
     * منبع اصلی و کامل برای شناسایی Entityها navigation_tree است، نه محتوای
     * چندین صفحه؛ پس نیازی به دنبال کردن لینک‌های داخل صفحه و گسترش صف نیست.
     *
     * @return array{pages: array<int, array>, navigation_tree: string}
     */
    public function crawl(string $baseUrl): array
    {
        $maxPages = (int) config('seobot.crawler.max_pages', 2);
        $timeout = (int) config('seobot.crawler.timeout', 15);
        $userAgent = config('seobot.crawler.user_agent', 'Mozilla/5.0 (compatible; SeoBotCrawler/1.0)');

        $parsed = parse_url($baseUrl);
        $host = $parsed['host'] ?? '';
        $host = preg_replace('/^www\./', '', strtolower($host));

        $this->navigationTree = null;
        $this->navHrefSet = [];

        Log::info('[Crawler] شروع کراول', [
            'base_url' => $baseUrl,
            'host' => $host,
            'max_pages' => $maxPages,
        ]);

        $visited = [];
        $pages = [];

        $queue = [$this->canonicalizeUrl($this->normalizeUrl($baseUrl, $baseUrl))];

        foreach (['/about', '/about-us', '/aboutus'] as $path) {
            $candidate = $this->canonicalizeUrl(rtrim($baseUrl, '/').$path);
            if (! in_array($candidate, $queue, true)) {
                $queue[] = $candidate;
            }
        }

        while (count($pages) < $maxPages && count($queue) > 0) {
            $url = array_shift($queue);

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            try {
                $fetched = $this->fetchPage($url, $userAgent, $timeout);

                if ($fetched['status'] < 200 || $fetched['status'] >= 300) {
                    Log::info('[Crawler] پاسخ ناموفق، رد شد', [
                        'url' => $url,
                        'status' => $fetched['status'],
                        'via' => $fetched['via'],
                    ]);
                    continue;
                }

                $contentType = $fetched['content_type'];

                if (! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml')) {
                    Log::info('[Crawler] content-type غیر HTML، رد شد', [
                        'url' => $url,
                        'content_type' => $contentType,
                        'via' => $fetched['via'],
                    ]);
                    continue;
                }

                $html = $fetched['body'];

                $pageData = $this->extractPageData($url, $html, $host);

                $pages[] = $pageData['content'];

                Log::info('[Crawler] صفحه کراول شد', [
                    'url' => $url,
                    'via' => $fetched['via'],
                    'title' => $pageData['content']['title'],
                    'headings_count' => count($pageData['content']['headings']),
                    'content_length' => mb_strlen($pageData['content']['content']),
                    'pages_so_far' => count($pages),
                ]);
            } catch (Throwable $e) {
                Log::warning('Crawler failed to fetch URL', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        if ($pages === []) {
            Log::error('[Crawler] هیچ صفحه‌ای دریافت نشد', ['base_url' => $baseUrl]);
            throw new RuntimeException('We couldn\'t fetch any pages from the site.');
        }

        Log::info('[Crawler] کراول تمام شد', [
            'base_url' => $baseUrl,
            'total_pages' => count($pages),
            'crawled_urls' => array_column($pages, 'url'),
            'navigation_tree_found' => $this->navigationTree !== null,
        ]);

        Log::info('[Crawler] درخت ناوبار استخراج‌شده'."\n".($this->navigationTree ?? '(چیزی پیدا نشد)'));

        return [
            'pages' => $pages,
            'navigation_tree' => $this->navigationTree ?? 'ناوبار قابل‌شناسایی از سایت استخراج نشد.',
        ];
    }

    /**
     * @return array{status:int, content_type:string, body:string, via:string}
     */
    protected function fetchPage(string $url, string $userAgent, int $timeout): array
    {
        $response = Http::withHeaders(['User-Agent' => $userAgent])
            ->connectTimeout((int) config('seobot.crawler.connect_timeout', 8))
            ->timeout($timeout)
            ->withOptions(['verify' => false])
            ->get($url);

        return [
            'status' => $response->status(),
            'content_type' => (string) $response->header('Content-Type', ''),
            'body' => $response->body(),
            'via' => 'direct',
        ];
    }

    /**
     * @return array{content: array}
     */
    protected function extractPageData(string $url, string $html, string $host): array
    {
        libxml_use_internal_errors(true);

        // زبانی که خود صفحه اعلام کرده (html lang / og:locale / content-language)؛ سیگنال «declared» تشخیص زبان
        $declaredLanguage = app(\App\Services\SiteLanguageDetector::class)->extractDeclaredLanguage($html);

        // کد JS/CSS قبل از parse حذف می‌شود: parser HTML اسکریپتی را که داخل رشته‌اش «</» دارد زود می‌بندد
        // و بقیه‌ی کد به‌عنوان «متن صفحه» نشت می‌کند (سایت فارسی به‌خاطر همین کدها انگلیسی تشخیص داده می‌شد).
        $html = (string) preg_replace(
            ['#<(script|style|template|noscript|svg)\b[^>]*>.*?</\1\s*>#is', '#<!--.*?-->#s'],
            ' ',
            $html
        );

        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);

        $title = trim($document->getElementsByTagName('title')->item(0)?->textContent ?? '');
        $metaDescription = '';

        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('name')) === 'description') {
                $metaDescription = trim($meta->getAttribute('content'));
                break;
            }
        }

        $headings = [];
        foreach (['h1', 'h2', 'h3'] as $tag) {
            foreach ($document->getElementsByTagName($tag) as $node) {
                $text = trim(preg_replace('/\s+/', ' ', $node->textContent));
                if ($text !== '') {
                    $headings[] = $text;
                }
            }
        }

        $bodyText = $this->extractBodyTextExcludingNavAndFooter($xpath, $document);

        $content = [
            'url' => $url,
            'title' => Str::limit($title, 200, ''),
            'meta_description' => Str::limit($metaDescription, 500, ''),
            'headings' => array_slice(array_values(array_unique($headings)), 0, 15),
            'content' => Str::limit($bodyText, 4000, ''),
            'html_lang' => $declaredLanguage,
        ];

        // ---- ناوبار: فقط یک‌بار در کل سایت استخراج می‌شود ----
        // به‌جای انتخاب یک کانتینر واحد (که در سایت‌هایی با چند منوی جدا
        // از هم مثل مگامنو/منوی موبایل/منوی فوتر باعث گم شدن لینک‌ها
        // می‌شد)، تمام کاندیدهای واجد شرایط را جمع و روی href یکتا می‌کنیم
        // تا هیچ لینکی از قلم نیفتد.
        if ($this->navigationTree === null) {
            $navAnchors = $this->collectAllNavAnchors($xpath);

            if (! empty($navAnchors)) {
                $this->navigationTree = $this->buildNavigationTreeFromAnchors($navAnchors, $url);

                $this->navHrefSet = [];
                foreach ($navAnchors as $anchor) {
                    $href = trim($anchor->getAttribute('href'));
                    if ($href !== '') {
                        $this->navHrefSet[$href] = true;
                    }
                }

                Log::info('[Crawler] ناوبار استخراج شد (merge شده از تمام کانتینرهای منو)', [
                    'page_url' => $url,
                    'nav_hrefs_count' => count($this->navHrefSet),
                    'nav_tree_lines' => substr_count($this->navigationTree, "\n") + 1,
                ]);
            } else {
                Log::warning('[Crawler] هیچ لینک ناوباری در این صفحه پیدا نشد', ['page_url' => $url]);
            }
        }

        libxml_clear_errors();

        return [
            'content' => $content,
        ];
    }

    /**
     * متن body را طوری استخراج می‌کند که محتوای منو/ناوبار و فوتر داخلش
     * تکرار نشود (چون همان اطلاعات از طریق navigation_tree جداگانه و
     * دقیق‌تر در اختیار AI قرار می‌گیرد). این کار هم از تکرار بی‌فایده‌ی
     * توکن جلوگیری می‌کند و هم سیگنال content را تمیزتر می‌کند.
     */
    protected function extractBodyTextExcludingNavAndFooter(DOMXPath $xpath, DOMDocument $document): string
    {
        $bodyNode = $document->getElementsByTagName('body')->item(0);

        if ($bodyNode === null) {
            return '';
        }

        $query = './/text()'
            .'[not(ancestor::nav)]'
            .'[not(ancestor::footer)]'
            .'[not(ancestor::*[contains(@class,"nav") or contains(@class,"menu") or contains(@class,"footer")])]'
            .'[not(ancestor::*[contains(@id,"nav") or contains(@id,"menu") or contains(@id,"footer")])]'
            .'[not(ancestor::script)]'
            .'[not(ancestor::style)]';

        $textNodes = $xpath->query($query, $bodyNode);

        if ($textNodes === false) {
            return $this->cleanText($bodyNode->textContent);
        }

        $buffer = '';
        foreach ($textNodes as $textNode) {
            $buffer .= ' '.$textNode->textContent;
        }

        return $this->cleanText($buffer);
    }

    /**
     * تمام anchorهایی که داخل هر کانتینر واجد شرایط ناوبار (تگ nav، یا هر
     * المانی که class/id آن حاوی nav یا menu باشد، به‌جز موارد واضحاً
     * نامرتبط مثل شبکه‌ی اجتماعی/سوییچر زبان/جستجو/breadcrumb) پیدا
     * می‌شوند را جمع‌آوری و بر اساس href یکتا می‌کند. این‌طوری اگر سایت
     * چند تا منوی جدا از هم داشته باشد (مگامنو، منوی موبایل، منوی فوتر)،
     * همه با هم merge می‌شوند و هیچ لینکی گم نمی‌شود.
     *
     * @return array<int, DOMElement>
     */
    protected function collectAllNavAnchors(DOMXPath $xpath): array
    {
        $seenByHref = [];

        // مرحله ۱: روش قبلی (کلاس/آی‌دی حاوی nav یا menu) — دقیق‌تر، وقتی جواب بده.
        $this->collectFromSemanticContainers($xpath, $seenByHref);

        // مرحله ۲ (fallback): اگر سایت از کلاس‌های utility (Tailwind و مشابه) استفاده کند
        // و هیچ container معنایی پیدا نشود یا لینک کمی بدهد، هر <div>/<ul> که حداقل
        // ۴ anchor مستقیمِ فرزند (نه از دل محتوای مقاله) دارد و در نیمه‌ی بالایی
        // ساختار DOM (قبل از اولین <main>/<article> یا معادلش) قرار دارد را هم
        // به‌عنوان کاندید ناوبار در نظر می‌گیریم.
        if (count($seenByHref) < 8) {
            $this->collectFromStructuralFallback($xpath, $seenByHref);
        }

        return array_values($seenByHref);
    }

    protected function collectFromSemanticContainers(DOMXPath $xpath, array &$seenByHref): void
    {
        $query = "//nav"
            ." | //*[contains(@class,'nav') or contains(@class,'menu')]"
            ." | //*[contains(@id,'nav') or contains(@id,'menu')]";

        $candidates = $xpath->query($query);
        if ($candidates === false || $candidates->length === 0) {
            return;
        }

        $excludeKeywords = ['social', 'lang-switch', 'language', 'search-form', 'breadcrumb', 'pagination'];

        foreach ($candidates as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $classAndId = strtolower($node->getAttribute('class').' '.$node->getAttribute('id'));

            $excluded = false;
            foreach ($excludeKeywords as $bad) {
                if (str_contains($classAndId, $bad)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
            }

            $anchors = $xpath->query('.//a[@href]', $node);
            if ($anchors === false || $anchors->length < 4) {
                continue;
            }

            foreach ($anchors as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }
                $href = trim($anchor->getAttribute('href'));
                if ($href === '' || isset($seenByHref[$href])) {
                    continue;
                }
                $seenByHref[$href] = $anchor;
            }
        }
    }

    /**
     * fallback ساختاری برای سایت‌هایی که از کلاس‌های utility (نه معنایی)
     * استفاده می‌کنند: هر container که مستقیماً (بدون واسطه‌ی یک anchor یا
     * container دیگر با ۴+ anchor) حداقل ۴ فرزند <a> با href معتبر دارد و
     * در ۳۰٪ ابتدایی بدنه‌ی سند قرار گرفته (یعنی جزو header/topbar است، نه
     * محتوای مقاله)، به‌عنوان بخشی از ناوبار در نظر گرفته می‌شود.
     */
    protected function collectFromStructuralFallback(DOMXPath $xpath, array &$seenByHref): void
    {
        $document = $xpath->document;
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return;
        }

        $allAnchorsInBody = $xpath->query('.//a[@href]', $body);
        $totalAnchors = $allAnchorsInBody instanceof \DOMNodeList ? $allAnchorsInBody->length : 0;
        if ($totalAnchors === 0) {
            return;
        }

        // فقط containerهایی که والدِ مستقیمِ چند anchor هستند (نه اجداد دوردست)
        $containers = $xpath->query('//*[count(./a[@href]) >= 4]');
        if ($containers === false || $containers->length === 0) {
            return;
        }

        $excludeTags = ['article', 'main'];

        foreach ($containers as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            // اگر داخل <article>/<main> (محتوای اصلی صفحه) است، رد کن —
            // احتمالاً لیست لینک داخل متن است، نه ناوبار سایت.
            $isInsideContent = false;
            $ancestor = $node->parentNode;
            while ($ancestor !== null) {
                if ($ancestor instanceof DOMElement && in_array(strtolower($ancestor->tagName), $excludeTags, true)) {
                    $isInsideContent = true;
                    break;
                }
                $ancestor = $ancestor->parentNode;
            }
            if ($isInsideContent) {
                continue;
            }

            $directAnchors = $xpath->query('./a[@href]', $node);
            if ($directAnchors === false) {
                continue;
            }

            foreach ($directAnchors as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }
                $href = trim($anchor->getAttribute('href'));
                if ($href === '' || $href === '#' || isset($seenByHref[$href])) {
                    continue;
                }
                $seenByHref[$href] = $anchor;
            }
        }
    }

    /**
     * از لیست anchorهای یکتا (که ممکن است از چند کانتینر جدا آمده باشند)
     * یک متن ساده و خوانا برای AI می‌سازد. عمق تودرتو بر اساس تعداد
     * <ul>/<ol> اجدادی نسبت به کل سند محاسبه می‌شود.
     *
     * برای سایت‌هایی با منوی خیلی بزرگ (صدها زیرشاخه)، به‌جای بریدن خام
     * لیست (که ممکن است وسط یک زیرمنو قطع شود و دسته‌های بعدی کلاً حذف
     * شوند)، ابتدا یک درخت واقعی ساخته می‌شود و سپس هرس هوشمند اعمال
     * می‌شود: سطح ریشه (دسته‌های اصلی) تا حد امکان کامل نگه داشته می‌شود،
     * و فقط تعداد زیرشاخه‌های هر گره محدود می‌شود.
     */
    protected function buildNavigationTreeFromAnchors(array $anchors, string $baseUrl): string
    {
        $items = [];
        $count = 0;

        foreach ($anchors as $anchor) {
            if ($count >= 500) {
                break;
            }

            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = trim($anchor->getAttribute('href'));

            if ($this->isExcludedNavUrl($href)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', $anchor->textContent ?? ''));

            // اگر anchor متن مستقیم ندارد (مثلاً فقط یک آیکون است)، قبل از
            // نادیده گرفتنش دنبال aria-label، title یا alt تصویر داخلش
            // می‌گردیم تا لینک‌های معتبر بدون متن ظاهری گم نشوند.
            if ($text === '') {
                $text = trim($anchor->getAttribute('aria-label'));

                if ($text === '') {
                    $text = trim($anchor->getAttribute('title'));
                }

                if ($text === '') {
                    $imgs = $anchor->getElementsByTagName('img');
                    if ($imgs->length > 0) {
                        $text = trim($imgs->item(0)->getAttribute('alt'));
                    }
                }
            }

            if ($text === '') {
                continue;
            }

            $depth = $this->computeMenuDepth($anchor);

            $isRealLink = $href !== ''
                && $href !== '#'
                && ! str_starts_with($href, 'javascript:')
                && ! str_starts_with($href, 'mailto:')
                && ! str_starts_with($href, 'tel:');

            $items[] = [
                'depth' => $depth,
                'label' => Str::limit($text, 100, ''),
                'url' => $isRealLink ? $this->canonicalizeUrl($this->normalizeUrl($href, $baseUrl)) : null,
            ];

            $count++;
        }

        if ($items === []) {
            return 'ناوبار قابل‌شناسایی از سایت استخراج نشد.';
        }

        $tree = $this->treeifyNavItems($items);

        $rootCap = (int) config('seobot.crawler.max_nav_root_items', 25);
        $prunedTree = $this->pruneNavigationTree($tree, $rootCap, 0);

        $lines = [];
        $this->flattenNavTree($prunedTree, 0, $lines);

        $maxTotal = (int) config('seobot.crawler.max_nav_total_items', 45);
        if (count($lines) > $maxTotal) {
            $omittedTotal = count($lines) - $maxTotal;
            $lines = array_slice($lines, 0, $maxTotal);
            $lines[] = "- ... و {$omittedTotal} آیتم دیگر در ناوبار (فهرست برای کنترل حجم پرامپت خلاصه شده است)";
        }

        return implode("\n", $lines);
    }

    /**
     * بررسی می‌کند آیا این href یکی از لینک‌های یوتیلیتی/غیر-Entity
     * (سبد خرید، حساب کاربری، شبکه‌های اجتماعی و...) است که نباید
     * به‌عنوان کاندیدی برای Entity در نظر گرفته شود.
     */
    protected function isExcludedNavUrl(string $href): bool
    {
        if ($href === '') {
            return false;
        }

        $normalized = strtolower($href);

        foreach (self::EXCLUDED_NAV_PATH_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * لیست flat آیتم‌های ناوبار (هرکدام با depth مشخص) را به یک درخت
     * واقعیِ parent → children تبدیل می‌کند تا بشود روی آن هرس هوشمند
     * (بر اساس عمق و سطح) اعمال کرد.
     *
     * @param  array<int, array{depth:int, label:string, url:?string}>  $items
     * @return array<int, object>
     */
    protected function treeifyNavItems(array $items): array
    {
        $root = [];
        $stack = []; // stack of stdClass nodes, index تقریبی = depth

        foreach ($items as $item) {
            $node = (object) [
                'label' => $item['label'],
                'url' => $item['url'],
                'children' => [],
                'isNote' => false,
            ];

            $depth = $item['depth'];

            while (count($stack) > $depth) {
                array_pop($stack);
            }

            if ($depth === 0 || empty($stack)) {
                $root[] = $node;
            } else {
                $parent = $stack[count($stack) - 1];
                $parent->children[] = $node;
            }

            $stack[] = $node;
        }

        return $root;
    }

    /**
     * هرس بازگشتیِ درخت ناوبار: در هر سطح، فقط N فرزند اول نگه داشته
     * می‌شود و بقیه با یک خط خلاصه («... و N مورد دیگر») جایگزین می‌شوند.
     * سطح ریشه (دسته‌های اصلی سایت) سخاوتمندانه‌تر و سطح‌های عمیق‌تر
     * (مثلاً لیست برندهای زیر یک دسته) محدودتر نگه داشته می‌شوند.
     *
     * @param  array<int, object>  $nodes
     * @return array<int, object>
     */
    protected function pruneNavigationTree(array $nodes, int $maxChildren, int $depth = 0): array
    {
        $total = count($nodes);
        $keep = array_slice($nodes, 0, $maxChildren);

        $result = [];
        foreach ($keep as $node) {
            $newNode = clone $node;
            $newNode->children = $this->pruneNavigationTree(
                $node->children,
                $this->childCapForDepth($depth + 1),
                $depth + 1
            );
            $result[] = $newNode;
        }

        $omitted = $total - count($keep);
        if ($omitted > 0) {
            $result[] = (object) [
                'label' => "... and {$omitted} more",
                'url' => null,
                'children' => [],
                'isNote' => true,
            ];
        }

        return $result;
    }

    /**
     * سقف تعداد فرزند مجاز برای هر سطح از عمق درخت ناوبار.
     */
    protected function childCapForDepth(int $depth): int
    {
        return $depth <= 1
            ? (int) config('seobot.crawler.max_nav_children_per_node', 8)
            : (int) config('seobot.crawler.max_nav_deep_children', 5);
    }

    /**
     * درخت هرس‌شده را دوباره به همان فرمت متنیِ indented قبلی تبدیل می‌کند.
     *
     * @param  array<int, object>  $nodes
     * @param  array<int, string>  $lines
     */
    protected function flattenNavTree(array $nodes, int $depth, array &$lines): void
    {
        foreach ($nodes as $node) {
            $indent = str_repeat('  ', min($depth, 6));

            if (! empty($node->isNote)) {
                $lines[] = "{$indent}- {$node->label}";
            } elseif ($node->url !== null) {
                $lines[] = "{$indent}- {$node->label} : {$node->url}";
            } else {
                $lines[] = "{$indent}- {$node->label} (دسته‌بندی، بدون لینک مستقیم)";
            }

            if (! empty($node->children)) {
                $this->flattenNavTree($node->children, $depth + 1, $lines);
            }
        }
    }

    /**
     * عمق یک anchor را بر اساس تعداد <ul>/<ol> اجدادی آن در کل سند حساب
     * می‌کند (مستقل از این‌که در کدام کانتینر منو قرار داشته باشد).
     */
    protected function computeMenuDepth(DOMNode $node): int
    {
        $depth = 0;
        $current = $node->parentNode;

        while ($current !== null) {
            if ($current instanceof DOMElement && in_array(strtolower($current->tagName), ['ul', 'ol'], true)) {
                $depth++;
            }
            $current = $current->parentNode;
        }

        return max(0, $depth - 1);
    }

    protected function normalizeUrl(string $href, string $baseUrl): string
    {
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        if (str_starts_with($href, '/')) {
            $parts = parse_url($baseUrl);

            return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$href;
        }

        if (! str_starts_with($href, 'http://') && ! str_starts_with($href, 'https://')) {
            return rtrim($baseUrl, '/').'/'.ltrim($href, '/');
        }

        return $href;
    }

    protected function canonicalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = preg_replace('/^www\./', '', strtolower($parts['host']));
        $path = $parts['path'] ?? '/';

        if ($path === '') {
            $path = '/';
        } elseif ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);

            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid'] as $tracking) {
                unset($queryParams[$tracking]);
            }

            if (! empty($queryParams)) {
                ksort($queryParams);
                $query = '?'.http_build_query($queryParams);
            }
        }

        return $scheme.'://'.$host.$path.$query;
    }

    protected function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text ?? '');
    }
}
