<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * تشخیص زبان اصلی سایت با سه سیگنال مستقل:
 *
 *  1) content  : تحلیل خود متن (نوع اسکریپت حروف + حروف/کلمات اختصاصی هر زبان).
 *                قابل‌اعتمادترین سیگنال است چون به تنظیمات اشتباه سایت وابسته نیست.
 *  2) declared : زبانی که خود سایت اعلام کرده (<html lang>، og:locale، Content-Language).
 *                مفید است ولی در عمل اغلب غلط است (مثلاً سایت فارسی وردپرسی با lang="en-US").
 *  3) ai       : زبانی که مدل برگردانده.
 *
 * قانون تصمیم: اگر محتوا با اطمینان بالا زبان را مشخص کند، همان نهایی است و بقیه فقط
 * اطمینان را بالا می‌برند. اگر متن کم یا مبهم بود (مثلاً سایت دوزبانه)، رأی‌گیری وزنی بین
 * سه سیگنال انجام می‌شود.
 *
 * این کلاس عمداً به Log و DB وابسته نیست تا مستقل تست شود.
 */
class SiteLanguageDetector
{
    /** کدهای ISO 639-1 که تشخیص‌شان پشتیبانی می‌شود. */
    public const SUPPORTED_CODES = [
        'fa', 'ar', 'ur', 'ps', 'ku', 'he',
        'en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'tr', 'pl', 'id',
        'ru', 'uk', 'zh', 'ja', 'ko', 'el', 'th', 'hi', 'bn', 'hy', 'ka',
    ];

    /** نام فارسی هر زبان (برای نمایش در پنل/لاگ). */
    public const NAMES = [
        'fa' => 'فارسی', 'ar' => 'عربی', 'ur' => 'اردو', 'ps' => 'پشتو', 'ku' => 'کردی', 'he' => 'عبری',
        'en' => 'انگلیسی', 'de' => 'آلمانی', 'fr' => 'فرانسوی', 'es' => 'اسپانیایی', 'it' => 'ایتالیایی',
        'pt' => 'پرتغالی', 'nl' => 'هلندی', 'tr' => 'ترکی استانبولی', 'pl' => 'لهستانی', 'id' => 'اندونزیایی',
        'ru' => 'روسی', 'uk' => 'اوکراینی', 'zh' => 'چینی', 'ja' => 'ژاپنی', 'ko' => 'کره‌ای',
        'el' => 'یونانی', 'th' => 'تایلندی', 'hi' => 'هندی', 'bn' => 'بنگالی', 'hy' => 'ارمنی', 'ka' => 'گرجی',
    ];

    /** حداقل اطمینان برای اینکه نتیجه‌ی محتوا به‌تنهایی کافی باشد. */
    protected const STRONG_CONFIDENCE = 70;

    /**
     * در رأی‌گیری حالت ضعیف، برنده باید حداقل این وزن را داشته باشد؛ وگرنه null (یعنی پیش‌فرض فارسی).
     * جلوی این را می‌گیرد که یک سایت فارسی با متن کم/لاتین (مثلاً اطمینان ۱۴٪) انگلیسی ثبت شود.
     */
    protected const MIN_DECISION_WEIGHT = 50;

    /**
     * اگر اسکریپت غالب لاتین باشد ولی دست‌کم این سهم از حروف به یک اسکریپت دیگر (مثلاً عربی/فارسی) تعلق
     * داشته باشد، زبان سایت همان اسکریپت دوم است: نام برند/مدل، کد و آدرس‌ها در هر سایتی لاتین‌اند،
     * ولی یک سایت انگلیسی تقریباً هیچ‌وقت ۱۵٪ متن فارسی/عربی ندارد.
     */
    protected const NON_LATIN_MIN_SHARE = 0.15;

    /** اطمینان در حالتی که اسکریپت غیرلاتین با قانون بالا انتخاب شده (بالای STRONG_CONFIDENCE). */
    protected const NON_LATIN_CONFIDENCE = 90;

    /** کمتر از این تعداد حرف، متن برای تشخیص کافی نیست. */
    protected const MIN_LETTERS = 15;

    /** سقف کاراکتر متنی که تحلیل می‌شود (کنترل مصرف CPU/حافظه). */
    protected const MAX_SAMPLE_CHARS = 60000;

    protected const SCRIPTS = [
        'arabic' => '/(?=\p{L})\p{Arabic}/u',
        'latin' => '/(?=\p{L})\p{Latin}/u',
        'cyrillic' => '/(?=\p{L})\p{Cyrillic}/u',
        // نکته: PCRE جدید علائم نگارشی مشترک (مثل «。») را هم جزو اسکریپت‌های ژاپنی/چینی/عربی
        // حساب می‌کند؛ پس همه‌جا فقط «حرف» (\p{L}) شمرده می‌شود.
        'han' => '/(?=\p{L})\p{Han}/u',
        'hiragana' => '/(?=\p{L})\p{Hiragana}/u',
        'katakana' => '/(?=\p{L})\p{Katakana}/u',
        'hangul' => '/(?=\p{L})\p{Hangul}/u',
        'hebrew' => '/(?=\p{L})\p{Hebrew}/u',
        'greek' => '/(?=\p{L})\p{Greek}/u',
        'thai' => '/(?=\p{L})\p{Thai}/u',
        'devanagari' => '/(?=\p{L})\p{Devanagari}/u',
        'bengali' => '/(?=\p{L})\p{Bengali}/u',
        'armenian' => '/(?=\p{L})\p{Armenian}/u',
        'georgian' => '/(?=\p{L})\p{Georgian}/u',
    ];

    /** اسکریپت‌هایی که تقریباً فقط یک زبان دارند. */
    protected const SINGLE_SCRIPT_LANGUAGES = [
        'hangul' => 'ko',
        'hebrew' => 'he',
        'greek' => 'el',
        'thai' => 'th',
        'devanagari' => 'hi',
        'bengali' => 'bn',
        'armenian' => 'hy',
        'georgian' => 'ka',
    ];

    /** حروف اختصاصی زبان‌های لاتین (کمک به تفکیک زبان‌هایی مثل ترکی/لهستانی). */
    protected const LATIN_MARKS = [
        'tr' => '\x{0131}\x{0130}\x{011F}\x{011E}\x{015F}\x{015E}',
        'de' => '\x{00DF}\x{00E4}\x{00C4}',
        'pl' => '\x{0142}\x{0105}\x{0119}\x{017A}\x{017C}\x{015B}\x{0107}\x{0144}',
        'es' => '\x{00F1}\x{00BF}\x{00A1}',
        'pt' => '\x{00E3}\x{00F5}',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $pages  خروجی SiteCrawlerService::crawl()['pages']
     * @param  string|null  $declared  مقدار خام زبان اعلام‌شده توسط سایت (مثلاً fa-IR)
     * @param  string|null  $aiLanguage  مقدار خام زبان برگشتی از AI (کد یا نام زبان)
     * @return array{code: ?string, confidence: int, source: string, signals: array<string, mixed>}
     */
    public function detect(array $pages, string $navigationTree = '', ?string $declared = null, ?string $aiLanguage = null): array
    {
        $content = $this->detectFromText($this->buildSample($pages, $navigationTree));
        $declaredCode = $this->normalizeCode($declared);
        $aiCode = $this->normalizeCode($aiLanguage);

        $signals = [
            'content' => $content,
            'declared' => ['raw' => $declared, 'code' => $declaredCode],
            'ai' => ['raw' => $aiLanguage, 'code' => $aiCode],
        ];

        // حالت قوی: خود محتوا با اطمینان بالا زبان را مشخص کرده.
        if ($content['code'] !== null && $content['confidence'] >= self::STRONG_CONFIDENCE) {
            $sources = ['content'];
            $confidence = $content['confidence'];

            foreach (['declared' => $declaredCode, 'ai' => $aiCode] as $name => $code) {
                if ($code === $content['code']) {
                    $sources[] = $name;
                    $confidence += 5;
                }
            }

            return [
                'code' => $content['code'],
                'confidence' => min(100, $confidence),
                'source' => implode('+', $sources),
                'signals' => $signals,
            ];
        }

        // حالت ضعیف (متن کم، سایت دوزبانه، ...): رأی‌گیری وزنی.
        // AI همان متن را دیده، پس مستقل از content نیست؛ برای همین وزنش کم است و
        // اعلام خود سایت (declared) در مبهم‌بودن محتوا تعیین‌کننده است.
        $tally = [];
        foreach ([
            ['declared', $declaredCode, 65],
            ['ai', $aiCode, 30],
            ['content', $content['code'], (int) round($content['confidence'] * 0.6)],
        ] as [$source, $code, $weight]) {
            if ($code === null) {
                continue;
            }
            $tally[$code]['weight'] = ($tally[$code]['weight'] ?? 0) + $weight;
            $tally[$code]['sources'][] = $source;
        }

        if ($tally === []) {
            return ['code' => null, 'confidence' => 0, 'source' => 'none', 'signals' => $signals];
        }

        uasort($tally, fn ($a, $b) => $b['weight'] <=> $a['weight']);
        $winner = array_key_first($tally);

        if ($tally[$winner]['weight'] < self::MIN_DECISION_WEIGHT) {
            return ['code' => null, 'confidence' => (int) $tally[$winner]['weight'], 'source' => 'weak', 'signals' => $signals];
        }

        return [
            'code' => $winner,
            'confidence' => min(95, (int) $tally[$winner]['weight']),
            'source' => implode('+', $tally[$winner]['sources']),
            'signals' => $signals,
        ];
    }

    /**
     * فقط از روی متن، زبان را تشخیص می‌دهد.
     *
     * @return array{code: ?string, confidence: int, letters: int, scripts: array<string, float>}
     */
    public function detectFromText(string $text): array
    {
        $text = preg_replace('~(?:https?://|www\.)\S+|\S+@\S+\.\S+~u', ' ', $text) ?? $text;
        $text = preg_replace('/<[^>]*>/u', ' ', $text) ?? $text;
        $text = $this->stripCode($text);

        $counts = [];
        foreach (self::SCRIPTS as $name => $pattern) {
            $counts[$name] = (int) preg_match_all($pattern, $text);
        }
        $counts['cjk'] = $counts['han'] + $counts['hiragana'] + $counts['katakana'];

        $groups = array_diff_key($counts, array_flip(['han', 'hiragana', 'katakana']));
        $total = array_sum($groups);

        if ($total < self::MIN_LETTERS) {
            return ['code' => null, 'confidence' => 0, 'letters' => $total, 'scripts' => []];
        }

        arsort($groups);
        $names = array_keys($groups);
        $top = $names[0];
        $topCount = $groups[$top];
        $secondCount = $groups[$names[1]] ?? 0;

        // سهم اسکریپت غالب؛ اگر دو اسکریپت تقریباً هم‌وزن باشند (سایت دوزبانه) اطمینان پایین می‌آید.
        $scriptConfidence = min(100, ($topCount / $total) * 100 + 10);
        if ($secondCount >= 0.8 * $topCount) {
            $scriptConfidence = min($scriptConfidence, 50);
        }

        // رقیب فقط لاتین است (برند/مدل/کد/آدرس در هر سایتی لاتین‌اند) و سهم قابل‌توجهی از متن به اسکریپت
        // دیگری است → زبان سایت همان اسکریپت است، با اطمینان بالا (حتی اگر لاتین کمی بیشتر یا هم‌وزن باشد)
        $nonLatin = array_values(array_diff($names, ['latin']))[0] ?? null;

        if ($nonLatin !== null && in_array('latin', array_slice($names, 0, 2), true)
            && $groups[$nonLatin] / $total >= self::NON_LATIN_MIN_SHARE) {
            $top = $nonLatin;
            $scriptConfidence = max(self::NON_LATIN_CONFIDENCE, min(100, ($groups[$nonLatin] / $total) * 100 + 10));
        }

        [$code, $within] = match ($top) {
            'arabic' => $this->resolveArabicScript($text, $counts['arabic']),
            'latin' => $this->resolveLatin($text),
            'cyrillic' => $this->resolveCyrillic($text),
            'cjk' => $this->resolveCjk($counts),
            default => [self::SINGLE_SCRIPT_LANGUAGES[$top] ?? null, 95],
        };

        $scripts = [];
        foreach ($groups as $name => $count) {
            if ($count > 0) {
                $scripts[$name] = round($count / $total, 3);
            }
        }

        return [
            'code' => $code,
            'confidence' => $code === null ? 0 : (int) round($scriptConfidence * $within / 100),
            'letters' => $total,
            'scripts' => $scripts,
        ];
    }

    /**
     * کد JS/CSS نشت‌کرده در متن صفحه را حذف می‌کند تا حروف لاتینِ کد، زبان را انگلیسی نشان ندهد:
     * فراخوانی تابع، شناسه‌های camelCase / snake_case / $ و مسیرهای نقطه‌دار (window.foo).
     * عمداً فقط توکن‌های قطعاً کد حذف می‌شوند تا متن واقعی انگلیسی (حتی با ; ) دست نخورد.
     */
    protected function stripCode(string $text): string
    {
        return (string) preg_replace([
            '/\b[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)+\b/u',     // window.foo.bar
            '/\b[A-Za-z_$][\w$]*\s*\(/u',                          // foo( / setInterval(
            '/\b[a-z]+[A-Z][A-Za-z0-9]*\b/u',                       // camelCase
            '/\b[A-Za-z0-9]*[_$][A-Za-z0-9_$]*\b/u',                // snake_case و $var
            '/\b(?:var|let|const|function|return|null|undefined|typeof)\b/u', // کلیدواژه‌های JS که در نثر انگلیسی نادرند
        ], ' ', $text);
    }

    /**
     * هر مقدار خام (fa، fa-IR، fa_IR، fas، Persian، فارسی، ...) را به کد دوحرفی ISO 639-1 تبدیل می‌کند.
     * اگر زبان در فهرست پشتیبانی نبود (یا 'other' بود) null برمی‌گرداند.
     */
    public function normalizeCode(?string $raw): ?string
    {
        $raw = trim(mb_strtolower((string) $raw));
        if ($raw === '') {
            return null;
        }

        $aliases = $this->aliasMap();
        if (isset($aliases[$raw])) {
            return $aliases[$raw];
        }

        $primary = preg_split('/[-_]/', $raw)[0] ?? '';

        $threeLetter = [
            'fas' => 'fa', 'per' => 'fa', 'ara' => 'ar', 'urd' => 'ur', 'pus' => 'ps', 'ckb' => 'ku', 'kur' => 'ku',
            'heb' => 'he', 'iw' => 'he', 'eng' => 'en', 'deu' => 'de', 'ger' => 'de', 'fra' => 'fr', 'fre' => 'fr',
            'spa' => 'es', 'ita' => 'it', 'por' => 'pt', 'nld' => 'nl', 'dut' => 'nl', 'tur' => 'tr', 'pol' => 'pl',
            'ind' => 'id', 'in' => 'id', 'rus' => 'ru', 'ukr' => 'uk', 'zho' => 'zh', 'chi' => 'zh', 'jpn' => 'ja',
            'kor' => 'ko', 'ell' => 'el', 'gre' => 'el', 'tha' => 'th', 'hin' => 'hi', 'ben' => 'bn',
            'hye' => 'hy', 'kat' => 'ka',
        ];
        $primary = $threeLetter[$primary] ?? $primary;

        return in_array($primary, self::SUPPORTED_CODES, true) ? $primary : null;
    }

    public function languageName(?string $code): ?string
    {
        return $code !== null ? (self::NAMES[$code] ?? null) : null;
    }

    /**
     * زبانی که خود سایت اعلام کرده را از صفحه‌ی اصلی می‌خواند
     * (<html lang>، og:locale، meta content-language، هدر Content-Language).
     * هر خطایی (timeout، SSL، ...) فقط null برمی‌گرداند؛ این سیگنال اختیاری است.
     */
    public function fetchDeclaredLanguage(string $url): ?string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SeoBot/1.0)'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            return $this->extractDeclaredLanguage(
                substr($response->body(), 0, 200000),
                $response->header('Content-Language') ?: null,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * اولویت: html lang  >  og:locale  >  meta content-language  >  هدر Content-Language
     */
    public function extractDeclaredLanguage(string $html, ?string $headerLanguage = null): ?string
    {
        $candidates = [];

        if (preg_match('/<html\b[^>]*?\s(?:xml:)?lang\s*=\s*["\']?([A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*)/i', $html, $m)) {
            $candidates[] = $m[1];
        }

        if (preg_match('/<meta\b[^>]*?property\s*=\s*["\']og:locale["\'][^>]*?content\s*=\s*["\']([^"\']+)/i', $html, $m)
            || preg_match('/<meta\b[^>]*?content\s*=\s*["\']([^"\']+)["\'][^>]*?property\s*=\s*["\']og:locale["\']/i', $html, $m)) {
            $candidates[] = $m[1];
        }

        if (preg_match('/<meta\b[^>]*?http-equiv\s*=\s*["\']content-language["\'][^>]*?content\s*=\s*["\']([^"\']+)/i', $html, $m)
            || preg_match('/<meta\b[^>]*?content\s*=\s*["\']([^"\']+)["\'][^>]*?http-equiv\s*=\s*["\']content-language["\']/i', $html, $m)) {
            $candidates[] = $m[1];
        }

        if ($headerLanguage) {
            $candidates[] = trim(explode(',', $headerLanguage)[0]);
        }

        foreach ($candidates as $candidate) {
            if ($this->normalizeCode($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // نمونه‌ی متن
    // ------------------------------------------------------------------

    protected function buildSample(array $pages, string $navigationTree): string
    {
        $parts = [];

        foreach ($pages as $page) {
            foreach (['title', 'meta_description'] as $key) {
                if (! empty($page[$key]) && is_string($page[$key])) {
                    $parts[] = $page[$key];
                }
            }

            if (! empty($page['headings']) && is_array($page['headings'])) {
                $parts[] = implode(' . ', array_map('strval', $page['headings']));
            }

            if (! empty($page['content']) && is_string($page['content'])) {
                $parts[] = mb_substr($page['content'], 0, 6000);
            }
        }

        // متن آیتم‌های منو هم سیگنال خوبی است (صفحه‌ی اصلی اغلب متن کمی دارد).
        $parts[] = mb_substr($navigationTree, 0, 8000);

        $sample = mb_substr(implode("\n", $parts), 0, self::MAX_SAMPLE_CHARS);

        // حذف بایت‌های نامعتبر UTF-8 تا regexهای /u خطا ندهند.
        return mb_convert_encoding($sample, 'UTF-8', 'UTF-8');
    }

    // ------------------------------------------------------------------
    // تفکیک زبان‌ها داخل هر اسکریپت. خروجی: [کد زبان, اطمینان ۰..۱۰۰]
    // ------------------------------------------------------------------

    /**
     * اسکریپت عربی: فارسی / عربی / اردو / پشتو / کردی سورانی
     */
    protected function resolveArabicScript(string $text, int $arabicLetters): array
    {
        $count = fn (string $class): int => (int) preg_match_all('/['.$class.']/u', $text);
        $letters = max(1, $arabicLetters);

        // حروف اختصاصی اردو (ٹ ڈ ڑ ں ے ہ ھ)
        if ($count('\x{0679}\x{0688}\x{0691}\x{06BA}\x{06D2}\x{06C1}\x{06BE}') / $letters >= 0.015) {
            return ['ur', 90];
        }
        // حروف اختصاصی پشتو (ټ ډ ړ ږ ښ ڼ ۍ ې)
        if ($count('\x{067C}\x{0689}\x{0693}\x{0696}\x{069A}\x{06BC}\x{06CD}\x{06D0}') / $letters >= 0.005) {
            return ['ps', 85];
        }
        // حروف اختصاصی کردی سورانی (ڕ ڵ ێ ۆ ە)
        if ($count('\x{0695}\x{06B5}\x{06CE}\x{06C6}\x{06D5}') / $letters >= 0.01) {
            return ['ku', 85];
        }

        // فارسی در برابر عربی
        $persianOnly = $count('\x{067E}\x{0686}\x{0698}\x{06AF}');   // پ چ ژ گ
        $persianDigits = $count('\x{06F0}-\x{06F9}');                 // ۰-۹
        $arabicDigits = $count('\x{0660}-\x{0669}');                  // ٠-٩

        preg_match_all('/(?:(?=\p{L})\p{Arabic})+/u', $this->foldArabicScript($text), $matches);
        $tokens = $matches[0];

        $faSet = $this->foldedSet($this->persianStopwords());
        $arSet = $this->foldedSet($this->arabicStopwords());

        $faStop = 0;
        $arStop = 0;
        $alTokens = 0;
        foreach ($tokens as $token) {
            if (isset($faSet[$token])) {
                $faStop++;
            }
            if (isset($arSet[$token])) {
                $arStop++;
            }
            // حرف تعریف «ال» در عربی بسیار رایج و در فارسی نادر است.
            if (str_starts_with($token, 'ال') && mb_strlen($token) >= 4) {
                $alTokens++;
            }
        }

        $faScore = $faStop + 0.5 * $persianOnly + 0.25 * $persianDigits;
        $arScore = $arStop + $alTokens + 0.25 * $arabicDigits;
        $total = $faScore + $arScore;

        if ($total <= 0) {
            // No signal at all: weak guess (low confidence so the vote decides). Arabic is the likelier market for Sevof.
            return ['ar', 35];
        }

        $margin = abs($faScore - $arScore) / $total;
        $confidence = 55 + 45 * $margin;
        // متن کم فقط وقتی اطمینان را پایین می‌آورد که نشانه‌ی عربی هم دیده شده باشد؛
        // نشانه‌ی فارسی (پ چ ژ گ / کلمات فارسی) بدون هیچ نشانه‌ی عربی یعنی فارسی قطعی
        if ($total < 5 && $arScore > 0) {
            $confidence = min($confidence, 60);
        }

        return [$faScore > $arScore ? 'fa' : 'ar', (int) round($confidence)];
    }

    protected function resolveLatin(string $text): array
    {
        $lower = mb_strtolower($text);
        preg_match_all('/\p{Latin}+/u', $lower, $matches);
        $freq = array_count_values($matches[0]);

        if ($freq === []) {
            return [null, 0];
        }

        $scores = [];
        foreach ($this->latinStopwords() as $code => $words) {
            $score = 0;
            foreach ($words as $word) {
                $score += $freq[$word] ?? 0;
            }
            $scores[$code] = $score;
        }

        foreach (self::LATIN_MARKS as $code => $class) {
            $scores[$code] += (int) preg_match_all('/['.$class.']/u', $lower);
        }

        arsort($scores);
        $codes = array_keys($scores);
        $top = $scores[$codes[0]];
        $second = $scores[$codes[1]] ?? 0;

        if ($top < 3) {
            // تقریباً هیچ کلمه‌ی شناخته‌شده‌ای نبود (مثلاً فقط نام برند)؛ اطمینان پایین.
            return [$top > 0 ? $codes[0] : null, 30];
        }

        $margin = ($top - $second) / $top;
        $volume = min(1, $top / 15);
        $confidence = 100 * (0.5 + 0.5 * $margin) * (0.6 + 0.4 * $volume);

        return [$codes[0], (int) round($confidence)];
    }

    protected function resolveCyrillic(string $text): array
    {
        $ukrainian = (int) preg_match_all('/[іїєґІЇЄҐ]/u', $text);
        $russian = (int) preg_match_all('/[ыэъёЫЭЪЁ]/u', $text);

        if ($ukrainian === 0 && $russian === 0) {
            return ['ru', 80];
        }

        $confidence = 60 + 40 * (abs($ukrainian - $russian) / ($ukrainian + $russian));

        return [$ukrainian > $russian ? 'uk' : 'ru', (int) round($confidence)];
    }

    protected function resolveCjk(array $counts): array
    {
        $kana = $counts['hiragana'] + $counts['katakana'];
        $kanaShare = $kana / max(1, $counts['cjk']);

        // وجود هیراگانا/کاتاکانا نشانه‌ی قطعی ژاپنی است؛ چینی کاتا ندارد.
        return $kanaShare >= 0.03 ? ['ja', 92] : ['zh', 88];
    }

    // ------------------------------------------------------------------
    // کمکی‌ها
    // ------------------------------------------------------------------

    /**
     * یکسان‌سازی اشکال مختلف نوشتن حروف تا متن فارسی با «ي/ك» عربی هم درست تشخیص داده شود.
     * (آ عمداً دست‌نخورده می‌ماند تا «آن» فارسی با «ان» اشتباه نشود.)
     */
    protected function foldArabicScript(string $text): string
    {
        $text = strtr($text, [
            "\u{06CC}" => "\u{064A}", // ی → ي
            "\u{0649}" => "\u{064A}", // ى → ي
            "\u{06A9}" => "\u{0643}", // ک → ك
            "\u{0623}" => "\u{0627}", // أ → ا
            "\u{0625}" => "\u{0627}", // إ → ا
            "\u{0671}" => "\u{0627}", // ٱ → ا
            "\u{200C}" => ' ',        // نیم‌فاصله = جداکننده‌ی توکن
            "\u{0640}" => '',         // کشیده
        ]);

        return preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
    }

    protected function foldedSet(array $words): array
    {
        $set = [];
        foreach ($words as $word) {
            $set[$this->foldArabicScript($word)] = true;
        }

        return $set;
    }

    /** فقط کلماتی که در عربی کاربرد رایج ندارند (کلمات مشترک مثل «و» و «من» عمداً نیامده‌اند). */
    protected function persianStopwords(): array
    {
        return [
            'در', 'به', 'از', 'که', 'این', 'را', 'با', 'است', 'برای', 'آن', 'یک', 'می', 'شود', 'های', 'ها',
            'تا', 'هم', 'یا', 'بر', 'کرد', 'کنید', 'باشد', 'شده', 'خود', 'همه', 'شما', 'نیز', 'هر', 'بود',
            'دارد', 'کنیم', 'ما', 'ایران', 'بیشتر', 'بین', 'درباره',
        ];
    }

    protected function arabicStopwords(): array
    {
        return [
            'في', 'على', 'إلى', 'عن', 'هذا', 'هذه', 'التي', 'الذي', 'الذين', 'مع', 'كان', 'كانت', 'هو', 'هي',
            'أو', 'كل', 'بعد', 'قبل', 'حتى', 'ذلك', 'لقد', 'نحن', 'عند', 'لدى', 'يمكن', 'خلال', 'حول', 'ضمن',
            'لكن', 'كما', 'قد', 'لم', 'لن', 'ليس', 'إنه', 'إنها', 'تم', 'وهو', 'فقط', 'جميع', 'أي',
        ];
    }

    /** کلمات پرتکرار (بدون کلمات یک‌حرفی که بین زبان‌ها مشترک و نویزی‌اند). */
    protected function latinStopwords(): array
    {
        return [
            'en' => ['the', 'and', 'of', 'with', 'that', 'are', 'our', 'your', 'you', 'this', 'from', 'for', 'have', 'will', 'can', 'about', 'more', 'all', 'not', 'what', 'which', 'their', 'they', 'by', 'be', 'we', 'is', 'to', 'in'],
            'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'für', 'von', 'den', 'dem', 'ein', 'eine', 'zu', 'auf', 'auch', 'wir', 'sie', 'bei', 'oder', 'sich', 'im', 'des', 'werden', 'unsere', 'über'],
            'fr' => ['le', 'la', 'les', 'des', 'et', 'est', 'pour', 'dans', 'que', 'qui', 'une', 'un', 'du', 'sur', 'avec', 'nous', 'vous', 'pas', 'au', 'aux', 'ce', 'cette', 'sont', 'nos', 'votre', 'plus'],
            'es' => ['el', 'la', 'los', 'las', 'de', 'en', 'que', 'para', 'con', 'por', 'una', 'un', 'del', 'se', 'su', 'nuestro', 'nuestros', 'más', 'es', 'al', 'como', 'sobre', 'servicios', 'nosotros'],
            'it' => ['il', 'lo', 'la', 'gli', 'le', 'di', 'che', 'per', 'con', 'una', 'un', 'del', 'della', 'dei', 'nel', 'nella', 'sono', 'non', 'più', 'nostri', 'nostro', 'anche', 'come'],
            'pt' => ['os', 'as', 'de', 'que', 'para', 'com', 'uma', 'um', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'não', 'nós', 'mais', 'serviços', 'você', 'são', 'nosso'],
            'nl' => ['de', 'het', 'een', 'en', 'van', 'voor', 'met', 'op', 'dat', 'zijn', 'niet', 'ook', 'ons', 'onze', 'wij', 'bij', 'naar', 'uw', 'deze', 'meer'],
            'tr' => ['ve', 'bir', 'bu', 'için', 'ile', 'de', 'da', 'çok', 'daha', 'en', 'olarak', 'gibi', 'ya', 'ise', 'her', 'hizmet', 'bizim', 'ne', 'kadar', 'ancak', 'veya'],
            'pl' => ['na', 'do', 'nie', 'się', 'jest', 'dla', 'to', 'że', 'oraz', 'jak', 'od', 'po', 'przez', 'nasze', 'nasz', 'są', 'lub', 'więcej'],
            'id' => ['dan', 'yang', 'di', 'untuk', 'dengan', 'dari', 'ini', 'kami', 'anda', 'pada', 'ke', 'adalah', 'dalam', 'atau', 'juga', 'kita', 'akan', 'tidak', 'layanan'],
        ];
    }

    protected function aliasMap(): array
    {
        $english = [
            'persian' => 'fa', 'farsi' => 'fa', 'arabic' => 'ar', 'urdu' => 'ur', 'pashto' => 'ps', 'kurdish' => 'ku',
            'hebrew' => 'he', 'english' => 'en', 'german' => 'de', 'french' => 'fr', 'spanish' => 'es',
            'italian' => 'it', 'portuguese' => 'pt', 'dutch' => 'nl', 'turkish' => 'tr', 'polish' => 'pl',
            'indonesian' => 'id', 'russian' => 'ru', 'ukrainian' => 'uk', 'chinese' => 'zh', 'japanese' => 'ja',
            'korean' => 'ko', 'greek' => 'el', 'thai' => 'th', 'hindi' => 'hi', 'bengali' => 'bn',
            'armenian' => 'hy', 'georgian' => 'ka',
        ];

        $persian = [];
        foreach (self::NAMES as $code => $name) {
            $persian[mb_strtolower($name)] = $code;
        }

        return $english + $persian + [
            'پارسی' => 'fa', 'ترکی' => 'tr', 'کره ای' => 'ko', 'کرهای' => 'ko', 'فارسی (ایران)' => 'fa',
        ];
    }
}
