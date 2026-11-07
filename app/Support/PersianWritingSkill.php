<?php

namespace App\Support;

/**
 * «مهارت» نوشتن فارسی ساده و روان: قوانین و نمونه‌ها در resources/prompts/fa-writing-skill.md
 * (بدون دست زدن به کد قابل ویرایش است) و فهرست کلمه‌ها اینجا، چون هم در prompt می‌آید و هم
 * بعد از نوشتن با کد چک می‌شود. هر جا متن فارسی تولید می‌شود از همین‌جا استفاده شود.
 */
final class PersianWritingSkill
{
    /** سقف طول جمله؛ جمله‌ی بلندتر بعد از نوشتن خودکار شکسته می‌شود. */
    public const MAX_SENTENCE_WORDS = 25;

    /**
     * کلمه‌ی کتابی/اداری، اصطلاح مشاوره‌ای و کلمه‌ی قرضیِ رایج ← معادل روزمره.
     * کنار این فهرست، قانون کلیِ «اصطلاح تخصصیِ هیچ حوزه‌ای ننویس» هم هست، چون اصطلاح هر حوزه فرق می‌کند.
     */
    public const WORD_SWAPS = [
        'به منظور' => 'برای',
        'در راستای' => 'برای',
        'در خصوص' => 'درباره',
        'پیرامون' => 'درباره',
        'بهره‌گیری' => 'استفاده',
        'بهره گیری' => 'استفاده',
        'به‌کارگیری' => 'استفاده',
        'به کارگیری' => 'استفاده',
        'حائز اهمیت' => 'مهم',
        'علی‌رغم' => 'با اینکه',
        'علیرغم' => 'با اینکه',
        'فلذا' => 'پس',
        'لذا' => 'پس',
        'مذکور' => 'این',
        'اتخاذ' => 'گرفتن یا انتخاب کردن',
        'می‌بایست' => 'باید',
        'مبرهن' => 'روشن',
        'امکان‌پذیر' => 'ممکن',
        'نمودن' => 'کردن',
        'می‌نماید' => 'می‌کند',
        'می‌نمایند' => 'می‌کنند',
        'نموده' => 'کرده',
        'نمایید' => 'کنید',
        'صورت می‌گیرد' => 'انجام می‌شود',
        'صورت گیرد' => 'انجام شود',
        'مورد استفاده قرار' => 'استفاده می‌شود',
        'مورد بررسی قرار' => 'بررسی می‌شود',
        'تسهیل' => 'آسان کردن',
        'رویکرد' => 'روش',
        'سازوکار' => 'روش کار',
        'ساز و کار' => 'روش کار',
        'مقیاس‌پذیری' => 'بزرگ کردن کار بدون دردسر',
        'مقیاس‌پذیر' => 'قابل گسترش',
        'هم‌راستا' => 'هماهنگ',
        'بهره‌وری' => 'کار بیشتر در وقت کمتر',
        'چارچوب' => 'روش یا الگو',
        'جریان کار' => 'مراحل کار',
        'انسجام' => 'به هم ربط داشتن',
        'اکوسیستم' => 'مجموعه',
        'این امر' => 'این کار',
        // کلمه‌های انگلیسیِ فارسی‌نویسی‌شده که خواننده‌ی عادی نمی‌فهمد
        'تنالیته' => 'لحن',
        'پرسونا' => 'خواننده‌ی هدف',
        'لید' => 'شروع مقاله',
        'فراخوان اقدام' => 'دعوت به کار',
        'کال تو اکشن' => 'دعوت به کار',
        'انگیجمنت' => 'تعامل',
        'اینسایت' => 'نکته',
        'کانتنت' => 'محتوا',
        'تارگت' => 'مخاطب هدف',
        'اپتیمایز' => 'بهینه کردن',
        'کیورد' => 'کلمه‌ی کلیدی',
        'استراتژی جامع' => 'برنامه‌ی کامل',
    ];

    /**
     * جایگزینی مستقیم در تیترها (تیتر بازنویسی خودکار نمی‌شود): حروف لاتین و کلمه‌های قرضی رایج.
     * فقط معادل‌های یک‌به‌یک که جمله را خراب نمی‌کنند.
     */
    public const HEADING_SWAPS = [
        'AI' => 'هوش مصنوعی',
        'SEO' => 'سئو',
        'CTA' => 'دعوت به کار',
        'KPI' => 'شاخص',
        'QA' => 'کنترل کیفیت',
        'UX' => 'تجربه‌ی کاربر',
        'Google' => 'گوگل',
        'WordPress' => 'وردپرس',
        'ChatGPT' => 'چت‌جی‌پی‌تی',
        'تنالیته' => 'لحن',
        'پرسونا' => 'خواننده‌ی هدف',
        'فراخوان اقدام' => 'دعوت به کار',
        'لید' => 'شروع مقاله',
    ];

    /**
     * تیتر فارسی بدون حروف لاتین رایج و کلمه‌ی قرضی (مثلاً «… با AI» → «… با هوش مصنوعی»).
     * $latin=false فقط کلمه‌های قرضی را عوض می‌کند (تیتر محصول که نام مدلش ممکن است AI یا Google داشته باشد).
     */
    public static function fixHeading(string $heading, bool $latin = true): string
    {
        foreach (self::HEADING_SWAPS as $from => $to) {
            if (! $latin && preg_match('/^[A-Za-z]/', $from)) {
                continue;
            }

            // نیم‌فاصله هم جزء کلمه است: «تنالیته‌ی» نباید «لحن‌ی» شود
            $heading = (string) preg_replace('/(?<![\p{L}\p{N}_\x{200C}])'.preg_quote($from, '/').'(?![\p{L}\p{N}_\x{200C}])/u', $to, $heading);
        }

        return $heading;
    }

    /** خلاصه‌ی قوانین برای جاهایی که prompt باید کوتاه بماند (ویرایش چت، توضیح محصول، بازنویسی خط). */
    public const COMPACT = <<<'TXT'
قوانین نوشتن فارسی (اکید):
- هر جمله فقط یک حرف بزند؛ بیشتر جمله‌ها ۸ تا ۱۸ کلمه و هیچ جمله‌ای بیشتر از ۲۵ کلمه.
- هر جمله به جمله‌ی قبل وصل باشد: از چیزی شروع کن که خواننده همین الان خوانده، بعد حرف تازه را بگو.
- فاعل یک آدم یا چیز واقعی باشد («شما»، «فروشنده»)، نه مفهوم انتزاعی («این فرایند»). با فعل بگو، نه با اسم («بررسی کنید» نه «انجام بررسی»).
- بیشتر از سه کلمه را با کسره پشت هم نیاور؛ وسط جمله فهرست کلمه‌های انتزاعی نیاور.
- الگوی ترجمه‌ای ننویس («زمانی اهمیت پیدا می‌کند که...»، «هدف X نیست؛ بلکه Y»).
- هیچ اصطلاح تخصصیِ هیچ حوزه‌ای (پزشکی، فنی، بازاریابی، مالی، حقوقی، سئو و...) و هیچ کلمه‌ی انگلیسیِ فارسی‌نویسی‌شده ننویس؛ همان چیز را با کلمه‌های روزمره بگو. فقط اگر خودِ آن اصطلاح موضوع مقاله است یا خواننده واقعاً باید یادش بگیرد، بار اول با یک جمله‌ی ساده معنی‌اش کن. آزمون: کسی که در این حوزه کار نمی‌کند، این کلمه را می‌فهمد؟ اگر نه، ساده‌اش کن. نمونه: «تنالیته» ← «لحن»، «پرسونا» ← «خواننده‌ی هدف»، «لید» ← «شروع مقاله»، «فراخوان اقدام» ← «دعوت به کار».
- مثال فرضی را فرضی بگو («فرض کنید...»، «مثلاً اگر...»)؛ هرگز ماجرای ساختگی را اتفاق واقعی جا نزن.
- در لیست شماره‌دار خودت عدد ننویس.
- کلمه‌ی روزمره و رایج بنویس، نه کتابی، اداری یا اصطلاح مشاوره‌ای؛ برای هر حرف کلی یک مثال از زندگی خواننده بیاور.
TXT;

    /** تعداد نمونه‌های بخش ۷ که نویسنده‌ی بخش‌ها می‌بیند (نمونه‌های مهم‌تر اول فایل‌اند). */
    public const WRITER_EXAMPLES = 3;

    /** نمونه‌های فهرست کلمه‌ها در writerPrompt() */
    private const WRITER_WORD_SAMPLES = ['به منظور', 'رویکرد', 'می‌نماید', 'چارچوب', 'تنالیته', 'کانتنت'];

    private static ?string $skill = null;

    private static ?string $writerSkill = null;

    /** کل مهارت (قوانین + نمونه‌ها + فهرست کلمه‌ها) برای نویسنده‌ی اصلی متن. */
    public static function prompt(): string
    {
        return self::$skill ??= str_replace('{WORD_LIST}', self::wordList(), self::raw());
    }

    /**
     * همان مهارت برای نویسنده‌ی بخش‌های مقاله که هر بخش جدا فراخوانی می‌شود (این متن ~۹ بار در هر مقاله تکرار می‌شود):
     * همه‌ی قوانین، فقط WRITER_EXAMPLES نمونه‌ی اول و به‌جای فهرست کامل کلمه‌ها چند نمونه.
     * فهرست کامل بعد از نوشتن با wordIssues() چک و با polish درست می‌شود، پس قانونش از دست نمی‌رود.
     */
    public static function writerPrompt(): string
    {
        if (self::$writerSkill !== null) {
            return self::$writerSkill;
        }

        $text = (string) preg_replace_callback('/(^## ۷\.[^\n]*\n\n)(.*?)(?=^## )/msu', function (array $m) {
            $examples = array_slice(preg_split('/\n{2,}/u', trim($m[2])) ?: [], 0, self::WRITER_EXAMPLES);

            return $m[1].implode("\n\n", $examples)."\n\n";
        }, self::raw());

        $samples = array_map(fn ($heavy) => "«{$heavy}» ← «".self::WORD_SWAPS[$heavy].'»', self::WRITER_WORD_SAMPLES);
        $words = '- کلمه‌ی کتابی، اداری یا انگلیسیِ فارسی‌نویسی‌شده ننویس؛ معادل روزمره‌اش را بنویس. نمونه: '.implode('، ', $samples).'.';

        return self::$writerSkill = str_replace('{WORD_LIST}', $words, $text);
    }

    private static function raw(): string
    {
        $file = resource_path('prompts/fa-writing-skill.md');

        return is_file($file) ? str_replace("\r\n", "\n", (string) file_get_contents($file)) : self::COMPACT;
    }

    /** نسخه‌ی کوتاه همراه فهرست کلمه‌ها. */
    public static function compact(): string
    {
        return self::COMPACT."\nکلمه‌های سنگین ← ساده:\n".self::wordList();
    }

    public static function wordList(): string
    {
        $lines = [];

        foreach (self::WORD_SWAPS as $heavy => $simple) {
            $lines[] = "- «{$heavy}» ← «{$simple}»";
        }

        return implode("\n", $lines);
    }

    /** @return array<int, string> */
    public static function wordIssues(string $text): array
    {
        return array_map(
            fn (string $word) => "کلمه‌ی سنگین «{$word}»؛ به‌جایش «".self::WORD_SWAPS[$word].'» یا معادل ساده‌ی دیگری بنویس و جمله را ساده نگه دار',
            PersianText::findPhrases($text, array_keys(self::WORD_SWAPS))
        );
    }

    /**
     * جمله‌های بلندتر از سقف (لینک‌ها، ** و نشانه‌ی لیست حساب نمی‌شوند).
     *
     * @return array<int, string>
     */
    public static function longSentences(string $text): array
    {
        $text = (string) preg_replace(['/\]\([^)]*\)/u', '/[*_#>`]+/u', '/^\s*(?:[-+]|\d+[.)])\s+/mu'], [']', ' ', ''], $text);
        $long = [];

        foreach (preg_split('/[.!?؟؛\n]+/u', $text) ?: [] as $sentence) {
            $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($words) > self::MAX_SENTENCE_WORDS) {
                $long[] = trim($sentence);
            }
        }

        return $long;
    }

    /** @return array<int, string> */
    public static function longSentenceIssues(string $text): array
    {
        return array_map(function (string $sentence) {
            $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $start = implode(' ', array_slice($words, 0, 6));

            return 'جمله‌ی '.count($words)." کلمه‌ای که با «{$start}…» شروع می‌شود؛ آن را به دو یا سه جمله‌ی کوتاه بشکن که هر کدام ادامه‌ی قبلی باشد";
        }, self::longSentences($text));
    }

    /** همه‌ی ایرادهای «ساده‌نویسی» یک متن، برای بازنویسی هدفمند خط‌ها. */
    public static function issues(string $text): array
    {
        return [...self::wordIssues($text), ...self::longSentenceIssues($text)];
    }

    public static function lineHasIssue(string $line): bool
    {
        return self::wordIssues($line) !== [] || self::longSentences($line) !== [];
    }
}
