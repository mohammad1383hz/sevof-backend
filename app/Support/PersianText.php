<?php

namespace App\Support;

/**
 * ویرایش ماشینی و امن متن فارسی (Markdown) خروجی AI: فقط خطاهایی که خواننده‌ی فارسی‌زبان
 * فوراً «ماشینی» حس می‌کند و اصلاحشان هیچ‌وقت معنا را عوض نمی‌کند.
 */
final class PersianText
{
    private const ZWNJ = "\u{200C}";

    /** عبارت‌های پرکننده/کلیشه‌ای که خواننده‌ی فارسی‌زبان آن‌ها را نشانه‌ی متن ماشینی می‌داند. */
    public const AI_CLICHES = [
        'در دنیای امروز', 'در دنیای پرشتاب امروز', 'در عصر حاضر', 'امروزه',
        'لازم به ذکر است', 'قابل ذکر است', 'شایان ذکر است', 'بدون شک', 'بی‌شک',
        'می‌توان گفت', 'به طور کلی', 'به‌طور کلی', 'در نهایت می‌توان گفت',
        'منحصر به فرد', 'منحصربه‌فرد', 'بی‌نظیر', 'فوق‌العاده', 'ایده‌آل', 'انتخابی عالی',
        'نقش حیاتی', 'نقش کلیدی', 'ایفا می‌کند', 'ایفا می‌کنند', 'از اهمیت بالایی برخوردار',
        'اهمیت ویژه‌ای دارد', 'کلید موفقیت', 'دنیای شگفت‌انگیز', 'به سطح بعدی',
        'در این مقاله قصد داریم', 'در این مقاله می‌خواهیم', 'در این بخش',
        'همان‌طور که گفتیم', 'همانطور که گفتیم', 'همان‌طور که اشاره شد', 'همانطور که اشاره شد',
        'با ما همراه باشید', 'فرصت را از دست ندهید',
    ];

    /**
     * کلمه‌های انگلیسی با حروف لاتین در متن فارسی (مثل AI، factual، QA)؛ آدرس و placeholder لینک‌ها
     * و نام‌های مجاز (مثلاً برند لاتین خود سایت) نادیده گرفته می‌شوند.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    public static function latinWords(string $text, array $allowed = []): array
    {
        $text = (string) preg_replace(['/\]\([^)]*\)/u', '~https?://\S+|www\.\S+~u'], [']', ' '], $text);
        // مرز کلمه با lookaround: در حالت /u حروف فارسی هم \w هستند و «KPIها» با \b پیدا نمی‌شود
        preg_match_all('/(?<![A-Za-z0-9_])[A-Za-z][A-Za-z\-]*[A-Za-z](?![A-Za-z0-9_])/u', $text, $m);
        $allowed = array_map('mb_strtolower', $allowed);

        return array_values(array_unique(array_filter(
            $m[0],
            fn (string $w) => ! in_array(mb_strtolower($w), $allowed, true)
        )));
    }

    /** @return array<int, string> */
    public static function latinWordIssues(string $text, array $allowed = []): array
    {
        return array_map(
            fn (string $w) => "کلمه‌ی انگلیسی «{$w}»؛ معادل رایج فارسی را بنویس (اسم برند یا ابزار را با حروف فارسی بنویس، مثل «گوگل» به‌جای Google)",
            self::latinWords($text, $allowed)
        );
    }

    /** @return array<int, string> کلمه‌های سنگین؛ فهرست و پیشنهاد جایگزین در PersianWritingSkill */
    public static function formalWordIssues(string $text): array
    {
        return PersianWritingSkill::wordIssues($text);
    }

    /**
     * قانون مشترک «زبان ساده» برای نویسنده‌ی مقاله و مقاله‌ی محصول: خواننده آدم معمولی است، نه متخصص.
     * مثال‌های جایگزینی فقط برای فارسی است؛ برای زبان‌های دیگر directive زبان خروجی آن‌ها را بی‌اثر می‌کند.
     */
    public const PLAIN_LANGUAGE_RULES = <<<'TXT'
PLAIN LANGUAGE (critical — the reader is an ordinary person, not an expert)
- Every sentence must be understood on the first read by someone with no background in the topic (think of a 15-year-old): everyday words, one idea per sentence.
- Always choose the simple, common word over the formal, literary or Arabic-heavy one. Persian examples: «به منظور/جهت»→«برای»، «نمودن»→«کردن»، «بهره‌گیری»→«استفاده»، «حائز اهمیت»→«مهم»، «علی‌رغم»→«با اینکه»، «فلذا/لذا»→«پس»، «مذکور»→«این»، «اتخاذ کردن»→«گرفتن»، «می‌بایست»→«باید»، «مبرهن است»→«روشن است»، «در راستای»→«برای».
- If a technical term is unavoidable, explain it in plain words the first time it appears (a short clause or a simple example).
- No long chains of abstract nouns; say plainly who does what.
- When a point is abstract, add a short everyday example.
- Warm and friendly, like explaining to a smart friend — never slangy, never childish, never academic.
- Be practical, like a good how-to blog: talk to the reader directly and tell them what to do. When the content is a set of actions or tips, use a short bullet or numbered list (one action per item), each with a short "why".
- Paragraphs: at most 3 short sentences. Break any longer block.
- No consultant or business jargon and no abstract frameworks; describe the real situation in plain words instead.
TXT;

    /** زیرمجموعه‌ی کوتاه برای داخل prompt (هر توکن prompt در هر بخش تکرار می‌شود)؛ تشخیص با کل AI_CLICHES است. */
    public const PROMPT_CLICHES = [
        'در دنیای امروز', 'امروزه', 'لازم به ذکر است', 'بدون شک', 'می‌توان گفت', 'به طور کلی',
        'منحصر به فرد', 'فوق‌العاده', 'نقش کلیدی', 'ایفا می‌کند', 'از اهمیت بالایی برخوردار', 'همان‌طور که گفتیم',
    ];

    /**
     * عبارت‌هایی از $phrases که در متن هستند؛ فاصله و نیم‌فاصله بین کلمات یکسان حساب می‌شود
     * ولی مرز کلمه رعایت می‌شود (مثلاً «امروزه» در «امروز هم» پیدا نمی‌شود).
     *
     * @return array<int, string>
     */
    public static function findPhrases(string $text, array $phrases): array
    {
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], $text);

        return array_values(array_filter($phrases, function (string $phrase) use ($text) {
            $words = preg_split('/[\s\x{200C}]+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $body = implode('[\s\x{200C}]*', array_map(fn ($w) => preg_quote($w, '/'), $words));

            return $body !== '' && preg_match('/(?<!\p{L})'.$body.'(?!\p{L})/u', $text) === 1;
        }));
    }

    public static function normalize(string $text): string
    {
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], $text);

        // شماره‌ی دوبل در لیست شماره‌دار («1. ۱.لید:» → «1. لید:»)
        $text = (string) preg_replace('/^(\s*\d+[.)]\s+)[۰-۹0-9]+\s*[.)\-–]\s*/mu', '$1', $text);

        // «می / نمی» جدا از فعل → نیم‌فاصله («می شود» → «می‌شود»)
        $text = (string) preg_replace('/(?<![\p{L}\x{200C}])(ن?می) (?=\p{Arabic})/u', '$1'.self::ZWNJ, $text);

        // پسوند جمع جدا («کتاب ها» → «کتاب‌ها»)؛ فقط وقتی «ها/های» خودش کلمه‌ی مستقل است
        $text = (string) preg_replace(
            '/(?<=\p{Arabic}) (ها|های|هایی|هایم|هایت|هایش|هایمان|هایتان|هایشان)(?![\p{L}\x{200C}])/u',
            self::ZWNJ.'$1',
            $text
        );

        // فاصله‌ی قبل از علائم نگارشی فارسی حذف، بعدشان یک فاصله
        $text = (string) preg_replace('/[ \t]+([،؛؟!.:])/u', '$1', $text);
        $text = (string) preg_replace('/([،؛؟])(?=[^\s\n»)\]])/u', '$1 ', $text);

        // نیم‌فاصله‌ی تکراری یا چسبیده به فاصله
        $text = (string) preg_replace('/\x{200C}{2,}/u', self::ZWNJ, $text);
        $text = (string) preg_replace('/ \x{200C}|\x{200C} /u', ' ', $text);

        // فاصله‌ی چندتایی وسط خط؛ تورفتگی ابتدای خط (لیست تودرتو) دست نمی‌خورد
        return (string) preg_replace('/(?<=\S)[ \t]{2,}(?=\S)/u', ' ', $text);
    }
}
