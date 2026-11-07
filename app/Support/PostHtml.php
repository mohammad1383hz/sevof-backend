<?php

namespace App\Support;

/**
 * کار امن با HTML خام پست‌های قدیمی وردپرس برای بازنویسی با AI:
 * - split: بخش‌بندی روی تیترهای H2 (یا H3 اگر H2 نیست) که کنار هم گذاشتنشان دقیقاً همان HTML اصلی است
 * - mask: هر چیزی که AI نباید دست بزند (تصویر، ویدیو، جدول، کد، شورت‌کد، نشانه‌های ویرایشگر بلوکی،
 *   تیتر H2 و آدرس لینک‌ها) با نشانه‌ی {{KEEP_n}} / {{A_n}} عوض می‌شود و بعد عیناً برمی‌گردد
 * - problems: خروجی AI فقط وقتی پذیرفته می‌شود که همه‌ی نشانه‌ها سر جایشان باشد و تگ خطرناکی نساخته باشد
 */
final class PostHtml
{
    /** تگ‌هایی که AI در متن تازه اجازه‌ی ساختنشان را ندارد. */
    protected const FORBIDDEN_TAGS = 'script|style|iframe|object|embed|form|input|button|svg|link|meta|img|picture|video|audio|table|h1|h2';

    /** تگ‌های متنی که باز و بسته شدنشان باید جفت باشد. */
    protected const BALANCED_TAGS = ['p', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'h3', 'h4', 'blockquote'];

    /**
     * @return array<int, array{id:int, type:string, heading:string, html:string}>
     */
    public static function split(string $html): array
    {
        $tag = preg_match('/<h2[\s>]/i', $html) ? 'h2' : 'h3';
        preg_match_all('/<'.$tag.'[\s>]/i', $html, $matches, PREG_OFFSET_CAPTURE);

        $cuts = [0];
        foreach ($matches[0] as [, $offset]) {
            // در ویرایشگر بلوکی، نشانه‌ی <!-- wp:heading --> قبل از تیتر هم جزو همان بخش است
            if (preg_match('/<!--\s*wp:heading\b[^>]*-->\s*$/', substr($html, 0, $offset), $comment, PREG_OFFSET_CAPTURE)) {
                $offset = $comment[0][1];
            }
            $cuts[] = $offset;
        }

        $cuts = array_values(array_unique($cuts));
        sort($cuts);
        $cuts[] = strlen($html);

        $sections = [];
        for ($i = 0; $i < count($cuts) - 1; $i++) {
            $part = substr($html, $cuts[$i], $cuts[$i + 1] - $cuts[$i]);

            if ($part === '') {
                continue;
            }

            $heading = preg_match('/^\s*(?:<!--\s*wp:heading\b[^>]*-->\s*)?<'.$tag.'\b[^>]*>(.*?)<\/'.$tag.'>/is', $part, $h)
                ? trim(html_entity_decode(strip_tags($h[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : '';
            $id = count($sections);

            $sections[] = [
                'id' => $id,
                'type' => self::sectionType($id, $heading),
                'heading' => $heading !== '' ? $heading : ($id === 0 ? 'مقدمه' : 'بخش '.($id + 1)),
                'html' => $part,
            ];
        }

        return $sections;
    }

    /** @param  array<int, string>  $sectionsHtml  به ترتیب */
    public static function join(array $sectionsHtml): string
    {
        return implode('', $sectionsHtml);
    }

    /**
     * @return array{0: string, 1: array<string, string>} [HTML نشانه‌گذاری‌شده، نشانه => متن اصلی]
     */
    public static function mask(string $html): array
    {
        $map = [];
        $n = 0;
        $keep = function (array $m) use (&$map, &$n) {
            $token = '{{KEEP_'.(++$n).'}}';
            $map[$token] = $m[0];

            return $token;
        };

        $html = (string) preg_replace_callback('/<!--[\s\S]*?-->/', $keep, $html);
        $html = (string) preg_replace_callback('/<(script|style|iframe|video|audio|figure|table|pre|code|form|svg|object|noscript|h1|h2|button|select|textarea)\b[\s\S]*?<\/\1\s*>/i', $keep, $html);
        $html = (string) preg_replace_callback('/<(img|embed|source|input|hr|picture)\b[^>]*>/i', $keep, $html);
        $html = (string) preg_replace_callback('/\[\/?[A-Za-z][\w-]*(?:\s[^\]]*)?\]/u', $keep, $html);

        $a = 0;
        $html = (string) preg_replace_callback('/<a\b[^>]*>/i', function (array $m) use (&$map, &$a) {
            $token = '{{A_'.(++$a).'}}';
            $map[$token] = $m[0];

            return $token;
        }, $html);

        return [$html, $map];
    }

    /** نشانه‌ها به متن اصلی برمی‌گردند (چند دور، چون یک بلوک ممکن است نشانه‌ی دیگری را در خود داشته باشد). */
    public static function restore(string $html, array $map): string
    {
        for ($i = 0; $i < 5 && str_contains($html, '{{'); $i++) {
            $next = strtr($html, $map);

            if ($next === $html) {
                break;
            }
            $html = $next;
        }

        return $html;
    }

    /** متن ساده‌ی یک بخش نشانه‌گذاری‌شده (برای برنامه‌ریزی و سنجش طول). */
    public static function text(string $masked): string
    {
        $text = (string) preg_replace('/\{\{(?:KEEP|A)_\d+\}\}/', ' ', $masked);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * ایرادهای خروجی AI نسبت به ورودی نشانه‌گذاری‌شده؛ آرایه‌ی خالی یعنی خروجی امن است.
     *
     * @return array<int, string>
     */
    public static function problems(string $maskedInput, string $output): array
    {
        $problems = [];

        // تصویر، جدول، شورت‌کد و... : همه، هر کدام یک بار، به همان ترتیب
        if (self::tokens($maskedInput, 'KEEP') !== self::tokens($output, 'KEEP')) {
            $problems[] = 'protected_blocks_changed';
        }

        // لینک‌ها: همه باقی بمانند (ترتیبشان می‌تواند با جمله‌بندی تازه عوض شود)
        $inLinks = self::tokens($maskedInput, 'A');
        $outLinks = self::tokens($output, 'A');
        sort($inLinks);
        sort($outLinks);
        if ($inLinks !== $outLinks || substr_count(strtolower($output), '</a>') !== substr_count(strtolower($maskedInput), '</a>')) {
            $problems[] = 'links_changed';
        }

        return [...$problems, ...self::unsafe($output)];
    }

    /**
     * تگ یا ویژگی خطرناک و تگ متنیِ جفت‌نشده در HTML تازه‌ای که AI نوشته.
     *
     * @return array<int, string>
     */
    public static function unsafe(string $html): array
    {
        $problems = [];

        if (preg_match('/<\s*('.self::FORBIDDEN_TAGS.')\b/i', $html)) {
            $problems[] = 'forbidden_tag';
        }

        if (preg_match('/\son[a-z]+\s*=|javascript\s*:|\sstyle\s*=/i', $html)) {
            $problems[] = 'forbidden_attribute';
        }

        foreach (self::BALANCED_TAGS as $tag) {
            if (preg_match_all('/<'.$tag.'(?:\s[^>]*)?>/i', $html) !== preg_match_all('/<\/'.$tag.'\s*>/i', $html)) {
                $problems[] = "unbalanced_{$tag}";
            }
        }

        return $problems;
    }

    /** @return array<int, string> */
    protected static function tokens(string $html, string $kind): array
    {
        preg_match_all('/\{\{'.$kind.'_\d+\}\}/', $html, $m);

        return $m[0];
    }

    protected static function sectionType(int $id, string $heading): string
    {
        if ($id === 0 && $heading === '') {
            return 'introduction';
        }

        if (preg_match('/سوال|سؤال|پرسش|FAQ/iu', $heading)) {
            return 'faq';
        }

        if (preg_match('/جمع[\s‌]?بندی|نتیجه[\s‌]?گیری|سخن پایانی|کلام آخر/u', $heading)) {
            return 'conclusion';
        }

        return 'section';
    }
}
