<?php
// app/Services/AI/BannerGeneratorService.php

namespace App\Services\AI;

use App\Models\Article;
use App\Models\Banner;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * سرویسِ مستقلِ تولید بنرِ تبلیغاتیِ یک سایت.
 *
 * === معماری: مدل فقط «متن» می‌نویسد، HTML را کد می‌سازد ===
 *
 * === تغییرِ این نسخه ===
 * متنِ خودِ بنر (headline/description/cta) دیگر با truncate() کاراکتری
 * سمتِ PHP بریده نمی‌شود، چون این برش گاهی وسطِ کلمه/جمله می‌افتاد و
 * خروجی‌هایی مثلِ «...تخصص و…» (بی‌معنی، وسط‌بریده) می‌ساخت. کلیپ‌شدنِ
 * بصریِ متن کاملاً به عهده‌ی CSSِ خودِ بنر است:
 *   - هدلاین: white-space:nowrap + overflow:hidden (کلیپِ پیکسلی تک‌خط)
 *   - توضیح: max-height ثابت + overflow:hidden (کلیپِ پیکسلیِ دو‌خط)
 * یعنی حتی اگر مدل طولانی‌تر از حدِ انتظار بنویسد، متن هرگز از کادرِ
 * بنر بیرون نمی‌زند و چیدمان به‌هم نمی‌ریزد — فقط دیگر با «…»ی
 * کاراکتریِ نصفه‌کاره‌ی PHP قطع نمی‌شود.
 *
 * === تغییرِ این پَچ ===
 * علتِ اصلیِ ظاهرشدنِ «…»یِ نصفه‌کاره (مثلِ «تخصص و…») این بود که در
 * systemPrompt به خودِ مدل عددِ سقفِ کاراکتری داده می‌شد. مدل برای
 * جا شدن در آن عدد، به‌جایِ نوشتنِ یک جمله‌ی کوتاه‌ترِ کامل، متن را
 * وسطِ کلمه می‌بُرید و خودش «…» اضافه می‌کرد. این محدودیتِ کاراکتریِ
 * سخت‌گیرانه از پرامپتِ مدل حذف شد؛ حالا فقط با تعدادِ کلمه راهنمایی
 * می‌شود و صراحتاً از مدل خواسته شده هیچ‌وقت جمله را نصفه رها نکند.
 * کلیپِ بصریِ نهایی همچنان (طبقِ همان معماری) کاملاً به عهده‌ی CSS در
 * renderBanner() است، پس طول‌بودنِ احتمالیِ متنِ مدل مشکلی ایجاد نمی‌کند.
 */
class BannerGeneratorService
{
    use RecordsTokenUsage;
    use \App\Services\AI\Concerns\UsesGapgptApiKey;

    protected const TARGET_HEIGHT_PX = 160;
    protected const MAX_WIDTH_PX = 1100;

    protected const MODEL = 'gpt-5-mini';

    public function __construct(protected SiteLogoResolver $logoResolver) {}

    public function generateForArticle(Article $article): Banner
    {
        $site = $article->site;

        $banner = new Banner();
        $banner->site_id = $site->id;
        $banner->site_name = $site->name;
        $banner->status = Banner::STATUS_GENERATING;
        $banner->save();

        try {
            $siteName = $this->siteName($site);
            $siteUrl = $this->resolveSiteUrl($site);
            $logoUrl = $this->logoResolver->resolve($site);
            $brandColors = $this->brandColorsFromLogo((array) $this->logoResolver->resolvePalette($site));
            $needColors = empty($brandColors);
            $style = $this->pickStyleDirective($site);
            $language = ContentLanguage::forSite($site);

            $banner->logo_url = $logoUrl;

            Log::info('ai.banner_generator.generate.start', [
                'article_id' => $article->id,
                'banner_id' => $banner->id,
                'has_logo' => (bool) $logoUrl,
                'brand_colors' => $brandColors,
                'site_url' => $siteUrl,
                'style' => $style['name'],
            ]);

            // نکته: عنوانِ مقاله عمداً در پرامپت نیست. بنر حالا برایِ کلِ سایت
            // استفاده‌ی مجدد می‌شود و باید به یک مقاله‌ی خاص گره نخورد.
            $userPrompt = $this->buildUserPrompt($site, $needColors);
            $banner->prompt = $userPrompt;

            $raw = $this->chat(
                model: self::MODEL,
                system: ContentLanguage::localize($this->systemPrompt($needColors), $language),
                user: $userPrompt,
                schemaName: 'article_banner',
                schema: $this->bannerSchema($needColors),
            );

            $copy = $this->normalizeCopy($raw, $siteName, $site->niche ?: null, $language, $siteUrl);
            $colors = $this->resolveColors($brandColors, $raw, $style);

            $banner->html = $this->renderBanner($style, $colors, $copy, $logoUrl, $siteUrl, $siteName, $language);
            $banner->status = Banner::STATUS_GENERATED;
            $banner->error_message = null;
            $banner->save();

            Log::info('ai.banner_generator.generate.done', [
                'article_id' => $article->id,
                'banner_id' => $banner->id,
                'html_length' => mb_strlen($banner->html),
                'style' => $style['name'],
                'headline' => $copy['headline'],
                'used_logo_colors' => ! $needColors,
            ]);

            return $banner;
        } catch (Throwable $e) {
            Log::error('ai.banner_generator.generate.failed', [
                'article_id' => $article->id,
                'banner_id' => $banner->id,
                'error' => $e->getMessage(),
            ]);

            $banner->status = Banner::STATUS_FAILED;
            $banner->error_message = $e->getMessage();
            $banner->save();

            throw $e;
        }
    }

    protected function siteName($site): string
    {
        $name = trim((string) ($site->name ?: $site->domain));

        return $name !== '' ? $name : 'کسب‌وکار';
    }

    protected function resolveSiteUrl($site): string
    {
        $domain = trim((string) $site->domain, "/ \t\n\r\0\x0B");

        if ($domain === '') {
            throw new RuntimeException('The site has no valid domain, so no banner can be built.');
        }

        if (! preg_match('#^https?://#i', $domain)) {
            $domain = 'https://'.$domain;
        }

        return $domain;
    }

    // ---------------------------------------------------------------
    // تنوعِ بصری: «جهتِ طراحی» را کد انتخاب می‌کند (هرگز دو بارِ پشتِ هم تکراری نیست)
    // ---------------------------------------------------------------

    protected function styleDirectives(): array
    {
        return [
            ['key' => 'warm', 'name' => 'گرادیانِ گرم و ارگانیک', 'fallback' => ['#f97316', '#db2777']],
            ['key' => 'neon', 'name' => 'دارک‌مودِ نئونی', 'fallback' => ['#22d3ee', '#a855f7']],
            ['key' => 'minimal', 'name' => 'مینیمالِ روشن با لهجه‌ی رنگی', 'fallback' => ['#2563eb', '#1e40af']],
            ['key' => 'duotone', 'name' => 'دو-تُنِ بولد (Duotone)', 'fallback' => ['#7c3aed', '#f59e0b']],
            ['key' => 'glass', 'name' => 'گلس‌مورفیسم', 'fallback' => ['#6366f1', '#ec4899']],
            ['key' => 'pastel', 'name' => 'پاستلِ نرم', 'fallback' => ['#f472b6', '#60a5fa']],
        ];
    }

    protected function pickStyleDirective($site): array
    {
        $directives = $this->styleDirectives();
        $cacheKey = "banner_last_style_index:{$site->id}";
        $lastIndex = Cache::get($cacheKey);

        $available = array_keys($directives);

        if ($lastIndex !== null && count($available) > 1) {
            $available = array_values(array_diff($available, [(int) $lastIndex]));
        }

        $chosen = $available[array_rand($available)];

        Cache::put($cacheKey, $chosen, now()->addDays(90));

        return $directives[$chosen];
    }

    // ---------------------------------------------------------------
    // رنگ‌ها: کنترلِ کامل با کد (کنتراستِ WCAG) به‌جایِ اعتماد به مدل
    // ---------------------------------------------------------------

    /**
     * @param  array<int, mixed>  $palette
     * @return string[]
     */
    protected function brandColorsFromLogo(array $palette): array
    {
        $vivid = [];

        foreach ($palette as $color) {
            $hex = $this->normalizeHex($color);

            if ($hex === null || in_array($hex, $vivid, true)) {
                continue;
            }

            if ($this->saturation($hex) < 0.3 || $this->luminance($hex) < 0.01) {
                continue;
            }

            $vivid[] = $hex;
        }

        return array_slice($vivid, 0, 2);
    }

    /**
     * @param  string[]  $brandColors
     * @return array{0:string,1:string}  [primary, secondary]
     */
    protected function resolveColors(array $brandColors, array $raw, array $style): array
    {
        if ($brandColors) {
            $p = $brandColors[0];
            $s = $brandColors[1] ?? $this->mix($this->rotateHue($p, -18), '#000000', 0.2);

            return [$p, $s];
        }

        $p = $this->normalizeHex($raw['primary'] ?? null);
        $s = $this->normalizeHex($raw['secondary'] ?? null);

        if ($p !== null) {
            return [$p, $s ?? $this->mix($this->rotateHue($p, -18), '#000000', 0.2)];
        }

        return $style['fallback'];
    }

    protected function normalizeHex(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }

        $c = strtolower(trim($color));

        if (preg_match('/^#?([0-9a-f]{3})$/', $c, $m)) {
            $h = $m[1];

            return '#'.$h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        if (preg_match('/^#?([0-9a-f]{6})(?:[0-9a-f]{2})?$/', $c, $m)) {
            return '#'.$m[1];
        }

        return null;
    }

    /** @return int[] */
    protected function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /** @param  array<int, float|int>  $rgb */
    protected function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(fn ($v) => (int) max(0, min(255, round($v))), $rgb));
    }

    protected function mix(string $a, string $b, float $t): string
    {
        [$r1, $g1, $b1] = $this->rgb($a);
        [$r2, $g2, $b2] = $this->rgb($b);

        return $this->hex([
            $r1 + ($r2 - $r1) * $t,
            $g1 + ($g2 - $g1) * $t,
            $b1 + ($b2 - $b1) * $t,
        ]);
    }

    protected function rotateHue(string $hex, float $degrees): string
    {
        [$h, $s, $l] = $this->rgbToHsl($hex);
        $h = fmod($h + $degrees + 360, 360);

        return $this->hslToHex($h, $s, $l);
    }

    /** @return array{0:float,1:float,2:float} [h(0-360), s(0-1), l(0-1)] */
    protected function rgbToHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, $this->rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    protected function hslToHex(float $h, float $s, float $l): string
    {
        if ($s === 0.0) {
            $v = $l * 255;

            return $this->hex([$v, $v, $v]);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $hk = $h / 360;

        $toChannel = function (float $t) use ($p, $q): float {
            $t = fmod($t + 1, 1);

            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }

            return $p;
        };

        return $this->hex([
            $toChannel($hk + 1 / 3) * 255,
            $toChannel($hk) * 255,
            $toChannel($hk - 1 / 3) * 255,
        ]);
    }

    protected function luminance(string $hex): float
    {
        $channel = function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        [$r, $g, $b] = $this->rgb($hex);

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    protected function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    protected function saturation(string $hex): float
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, $this->rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        return $max == 0 ? 0.0 : ($max - $min) / $max;
    }

    protected function bestText(string ...$bgs): string
    {
        $light = '#ffffff';
        $dark = '#111827';

        $score = fn (string $text): float => min(array_map(fn (string $bg): float => $this->contrast($text, $bg), $bgs));

        return $score($light) >= $score($dark) ? $light : $dark;
    }

    protected function darkenUntil(string $hex, string $text, float $min = 4.5): string
    {
        for ($i = 0; $i < 12 && $this->contrast($hex, $text) < $min; $i++) {
            $hex = $this->mix($hex, '#000000', 0.1);
        }

        return $hex;
    }

    protected function lightenUntil(string $hex, float $minLuminance): string
    {
        for ($i = 0; $i < 10 && $this->luminance($hex) < $minLuminance; $i++) {
            $hex = $this->mix($hex, '#ffffff', 0.12);
        }

        return $hex;
    }

    /** @return array{0:string,1:string,2:string}  [p, s, textColor] */
    protected function fitBackground(string $p, string $s): array
    {
        $text = $this->bestText($p, $s);

        if (min($this->contrast($text, $p), $this->contrast($text, $s)) < 4.5) {
            $text = '#ffffff';
            $p = $this->darkenUntil($p, $text);
            $s = $this->darkenUntil($s, $text);
        }

        return [$p, $s, $text];
    }

    protected function invertedButton(string $text, string $tint): array
    {
        return $text === '#ffffff'
            ? ['#ffffff', $this->darkenUntil($tint, '#ffffff', 4.5)]
            : ['#111827', '#ffffff'];
    }

    // ---------------------------------------------------------------
    // شکل‌هایِ تزئینی
    // ---------------------------------------------------------------

    /** @param array<string,string> $pos */
    protected function decorCircle(string $bg, string $size, array $pos): string
    {
        $posCss = '';
        foreach ($pos as $edge => $value) {
            $posCss .= "{$edge}:{$value};";
        }

        return '<div style="position:absolute;'.$posCss.'width:'.$size.';height:'.$size.';border-radius:50%;background:'.$bg.';"></div>';
    }

    /** @param array<string,string> $pos */
    protected function decorBar(string $bg, string $width, string $height, array $pos, float $deg, string $radius = '3px'): string
    {
        $posCss = '';
        foreach ($pos as $edge => $value) {
            $posCss .= "{$edge}:{$value};";
        }

        return '<div style="position:absolute;'.$posCss.'width:'.$width.';height:'.$height.';border-radius:'.$radius.';background:'.$bg.';transform:rotate('.$deg.'deg);"></div>';
    }

    // ---------------------------------------------------------------
    // Templateهای بصری
    // ---------------------------------------------------------------

    /**
     * @return array{bg:string,row:string,vBorder:int,title:string,desc:string,logoBg:string,avatarBg:string,avatarFg:string,btn:string}
     */
    protected function theme(string $key, string $p, string $s): array
    {
        $white = '#ffffff';

        switch ($key) {
            case 'neon':
                $bg1 = '#0b1120';
                $bg2 = $this->mix($bg1, $p, 0.18);
                $neon = $this->lightenUntil($p, 0.25);
                $glow = $this->lightenUntil($s, 0.2);
                $onNeon = $this->bestText($neon);

                return [
                    'bg' => "background-color:{$bg1};background-image:radial-gradient(circle at 94% 0%,{$neon}40 0,{$neon}00 52%),radial-gradient(circle at 4% 115%,{$glow}33 0,{$glow}00 48%),linear-gradient(135deg,{$bg1},{$bg2});border-top:3px solid {$neon};",
                    'row' => '',
                    'radius' => 16,
                    'vBorder' => 3,
                    'title' => $white,
                    'desc' => '#ffffffb3',
                    'logoBg' => $white,
                    'avatarBg' => $neon,
                    'avatarFg' => $onNeon,
                    'btn' => "background:{$neon};color:{$onNeon};border-radius:10px;box-shadow:0 4px 14px {$neon}66;",
                    'decor' => [
                        $this->decorCircle($neon.'40', '150px', ['top' => '-70px', 'right' => '-40px']),
                        $this->decorCircle($glow.'33', '100px', ['bottom' => '-50px', 'left' => '4%']),
                        $this->decorBar($glow.'cc', '48px', '3px', ['bottom' => '16px', 'left' => '-8px'], -10),
                        $this->decorCircle($neon.'80', '6px', ['top' => '16px', 'right' => '14%']),
                    ],
                ];

            case 'minimal':
                $accent = $this->darkenUntil($p, $white, 4.5);
                $tint = $this->mix($p, $white, 0.93);
                $line = $this->mix($p, $white, 0.8);

                return [
                    'bg' => "background-color:{$tint};border:1px solid {$line};border-right:5px solid {$accent};",
                    'row' => '',
                    'radius' => 12,
                    'vBorder' => 2,
                    'title' => '#0f172a',
                    'desc' => '#475569',
                    'logoBg' => $white,
                    'avatarBg' => $accent,
                    'avatarFg' => $white,
                    'btn' => "background:{$accent};color:{$white};border-radius:10px;",
                    'decor' => [
                        $this->decorCircle($this->mix($accent, $white, 0.75), '90px', ['top' => '-40px', 'left' => '-30px']),
                        $this->decorCircle($accent.'22', '10px', ['top' => '18px', 'right' => '12%']),
                        $this->decorBar($accent.'33', '60px', '2px', ['bottom' => '20px', 'right' => '-6px'], -14),
                    ],
                ];

            case 'duotone':
                if ($this->contrast($p, $s) < 2.2 || abs($this->luminance($p) - $this->luminance($s)) < 0.12) {
                    $s = $this->mix($p, '#000000', 0.5);
                }
                [$p, $s, $text] = $this->fitBackground($p, $s);
                [$btnBg, $btnFg] = $this->invertedButton($text, $s);

                return [
                    'bg' => "background-color:{$p};background-image:linear-gradient(250deg,{$p} 0,{$p} 58%,{$s} 58%,{$s} 100%);",
                    'row' => '',
                    'radius' => 10,
                    'vBorder' => 0,
                    'title' => $text,
                    'desc' => $text.'e6',
                    'logoBg' => $white,
                    'avatarBg' => $white,
                    'avatarFg' => $this->darkenUntil($p, $white, 4.5),
                    'btn' => "background:{$btnBg};color:{$btnFg};border-radius:8px;",
                    'decor' => [
                        $this->decorBar($white.'26', '120px', '120px', ['top' => '-50px', 'right' => '26%'], 45, '18px'),
                        $this->decorCircle($white.'2e', '14px', ['bottom' => '18px', 'right' => '8%']),
                        $this->decorCircle($text.'14', '8px', ['top' => '20px', 'left' => '18%']),
                    ],
                ];

            case 'glass':
                [$p, $s, $text] = $this->fitBackground($p, $s);

                return [
                    'bg' => "background-color:{$p};background-image:radial-gradient(circle at 10% 0%,#ffffff2e 0,#ffffff00 40%),radial-gradient(circle at 92% 105%,#ffffff26 0,#ffffff00 45%),linear-gradient(135deg,{$p},{$s});",
                    'row' => 'margin:10px;height:calc(100% - 22px);background:#ffffff24;border:1px solid #ffffff47;border-radius:12px;',
                    'radius' => 18,
                    'vBorder' => 0,
                    'title' => $text,
                    'desc' => $text.'e6',
                    'logoBg' => $white,
                    'avatarBg' => $white,
                    'avatarFg' => $this->darkenUntil($p, $white, 4.5),
                    'btn' => "background:#ffffff26;color:{$text};border:1px solid {$text}80;border-radius:10px;",
                    'decor' => [
                        $this->decorCircle('#ffffff33', '80px', ['top' => '-24px', 'left' => '18%']),
                        $this->decorCircle('#ffffff22', '54px', ['bottom' => '-18px', 'right' => '14%']),
                        $this->decorCircle('#ffffff40', '10px', ['top' => '20px', 'right' => '8%']),
                    ],
                ];

            case 'pastel':
                $c1 = $this->mix($p, $white, 0.82);
                $c2 = $this->mix($s, $white, 0.85);
                $d1 = $this->mix($p, $white, 0.55);
                $d2 = $this->mix($s, $white, 0.6);
                $d3 = $this->mix($p, $white, 0.7);
                $btn = $this->darkenUntil($p, $white, 4.5);

                return [
                    'bg' => "background-color:{$c1};background-image:radial-gradient(circle at 5% 14%,{$d1} 0,{$d1} 7px,#ffffff00 8px),radial-gradient(circle at 9% 27%,{$d2} 0,{$d2} 4px,#ffffff00 5px),radial-gradient(circle at 3.5% 30%,{$d3} 0,{$d3} 3px,#ffffff00 4px),linear-gradient(135deg,{$c1},{$c2});",
                    'row' => '',
                    'radius' => 20,
                    'vBorder' => 0,
                    'title' => '#1f2937',
                    'desc' => '#4b5563',
                    'logoBg' => $white,
                    'avatarBg' => $btn,
                    'avatarFg' => $white,
                    'btn' => "background:{$btn};color:{$white};border-radius:999px;",
                    'decor' => [
                        $this->decorCircle($d2, '26px', ['top' => '14px', 'right' => '10%']),
                        $this->decorCircle($d3, '16px', ['bottom' => '16px', 'right' => '20%']),
                        $this->decorCircle($c2, '60px', ['bottom' => '-24px', 'left' => '-18px']),
                    ],
                ];

            case 'warm':
            default:
                [$p, $s, $text] = $this->fitBackground($p, $s);
                [$btnBg, $btnFg] = $this->invertedButton($text, $p);

                return [
                    'bg' => "background-color:{$p};background-image:radial-gradient(circle at 8% 0%,#ffffff40 0,#ffffff00 46%),radial-gradient(circle at 96% 115%,#ffffff2e 0,#ffffff00 42%),linear-gradient(135deg,{$p},{$s});",
                    'row' => '',
                    'radius' => 18,
                    'vBorder' => 0,
                    'title' => $text,
                    'desc' => $text.'e6',
                    'logoBg' => $white,
                    'avatarBg' => $white,
                    'avatarFg' => $this->darkenUntil($p, $white, 4.5),
                    'btn' => "background:{$btnBg};color:{$btnFg};border-radius:999px;box-shadow:0 4px 12px #00000033;",
                    'decor' => [
                        $this->decorCircle('#ffffff38', '100px', ['top' => '-36px', 'left' => '-24px']),
                        $this->decorCircle('#ffffff22', '56px', ['bottom' => '-20px', 'right' => '20%']),
                        $this->decorCircle($text.'26', '8px', ['top' => '18px', 'right' => '10%']),
                    ],
                ];
        }
    }

    protected function fluid(string $prop, string $fixed, string $fluid): string
    {
        return "{$prop}:{$fixed};{$prop}:{$fluid};";
    }

    protected function wrapBanner(string $html): string
    {
        $wrapperStyle = 'width:100%;max-width:'.self::MAX_WIDTH_PX.'px;height:'.self::TARGET_HEIGHT_PX.'px;overflow:hidden;margin:0 auto;display:flex;align-items:center;justify-content:center;';

        return '<div style="'.$wrapperStyle.'"><div style="width:100%;flex-shrink:0;">'.$html.'</div></div>';
    }

    /**
     * ساختِ HTMLِ نهاییِ بنر — تک‌خطی (بدونِ newline) تا wpautop هیچ <br> اضافه نکند.
     * همه‌ی متن‌ها/آدرس‌ها escape می‌شوند؛ مدل هیچ‌وقت تگ یا لینک تولید نمی‌کند.
     *
     * متنِ headline/description دیگر در PHP کاراکتری بریده نشده (نگاه کنید
     * به normalizeCopy)، پس اگر مدل طولانی‌تر از انتظار بنویسد، همینجا با
     * CSS به‌صورتِ پیکسلی و تمیز کلیپ می‌شود:
     *   - هدلاین: white-space:nowrap + overflow:hidden → تک‌خط، بدونِ
     *     شکستنِ ارتفاع، برشِ لبه‌ای (بدونِ «…»یِ نصفه‌کاره).
     *   - توضیح: max-height:39px + overflow:hidden → دقیقاً به‌اندازه‌ی
     *     دو خط، بدونِ نیاز به line-clamp.
     */
    protected function renderBanner(array $style, array $colors, array $copy, ?string $logoUrl, string $siteUrl, string $siteName, string $language = ContentLanguage::DEFAULT): string
    {
        [$p, $s] = $colors;
        $rtl = ContentLanguage::isRtl($language);
        $t = $this->theme($style['key'], $p, $s);
        $e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $height = self::TARGET_HEIGHT_PX - $t['vBorder'];
        $radius = $t['radius'] ?? 14;

        $container = 'position:relative;max-width:'.self::MAX_WIDTH_PX.'px;height:'.$height.'px;overflow:hidden;margin:0 auto;border-radius:'.$radius.'px;direction:'.($rtl ? 'rtl' : 'ltr').';box-shadow:0 10px 26px #00000021,0 2px 8px #00000014;'.$t['bg'];

        $decor = implode('', $t['decor'] ?? []);

        $row = 'position:relative;z-index:1;display:flex;flex-wrap:nowrap;align-items:center;justify-content:space-between;height:100%;'
            .$this->fluid('gap', '12px', 'clamp(6px,2.5vw,12px)')
            .$this->fluid('padding', '0 20px', '0 clamp(10px,3vw,20px)')
            .$t['row'];

        $logoBox = $this->fluid('width', '56px', 'clamp(38px,10vw,56px)')
            .$this->fluid('height', '56px', 'clamp(38px,10vw,56px)');

        if ($logoUrl) {
            $logo = '<div style="flex:0 0 auto;'.$logoBox.'border-radius:12px;background:'.$t['logoBg'].';overflow:hidden;display:flex;align-items:center;justify-content:center;">'
                .'<img src="'.$e($logoUrl).'" alt="'.($rtl ? 'لوگو' : 'logo').'" style="max-width:86%;max-height:86%;object-fit:contain;display:block;">'
                .'</div>';
        } else {
            $initial = mb_strtoupper(mb_substr($siteName, 0, 1));
            $logo = '<div style="flex:0 0 auto;'.$logoBox.'border-radius:50%;background:'.$t['avatarBg'].';color:'.$t['avatarFg'].';display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:22px;">'
                .$e($initial)
                .'</div>';
        }

        $description = $copy['description'] !== ''
            ? '<div style="'.$this->fluid('font-size', '13px', 'clamp(11px,3vw,13px)').'line-height:1.5;margin-top:4px;color:'.$t['desc'].';max-height:39px;overflow:hidden;word-break:break-word;">'.$e($copy['description']).'</div>'
            : '';

        $text = '<div style="flex:1 1 auto;min-width:0;max-height:100%;overflow:hidden;text-align:'.($rtl ? 'right' : 'left').';">'
            .'<div style="'.$this->fluid('font-size', '18px', 'clamp(14px,3.8vw,18px)').'font-weight:bold;line-height:1.4;color:'.$t['title'].';white-space:nowrap;overflow:hidden;">'.$e($copy['headline']).'</div>'
            .$description
            .'</div>';

        $cta = '<a href="'.$e($siteUrl).'" target="_blank" rel="noopener noreferrer" style="flex:0 0 auto;white-space:nowrap;text-align:center;text-decoration:none;font-weight:bold;'
            .$this->fluid('font-size', '13px', 'clamp(11px,3vw,13px)')
            .$this->fluid('padding', '0 16px', '0 clamp(8px,2.6vw,16px)')
            .'height:36px;line-height:36px;'.$t['btn'].'">'.$e($copy['cta']).($rtl ? ' ←' : ' →').'</a>';

        $inner = '<div style="'.$container.'">'.$decor.'<div style="'.$row.'">'.$logo.$text.$cta.'</div></div>';

        return $this->wrapBanner($inner);
    }

    // ---------------------------------------------------------------
    // متن‌هایِ خودِ بنر: فقط پاک‌سازی، بدونِ برشِ کاراکتری
    // ---------------------------------------------------------------

    /**
     * @return array{headline:string,description:string,cta:string}
     */
    protected function normalizeCopy(array $raw, string $siteName, ?string $niche, string $language = ContentLanguage::DEFAULT, string $siteUrl = ''): array
    {
        // فقط tagها و فاصله‌هایِ اضافه پاک می‌شوند؛ دیگر هیچ برشِ طولی
        // (truncate) روی متنِ خودِ بنر اعمال نمی‌شود، چون آن برش گاهی
        // وسطِ کلمه/جمله می‌افتاد و متنِ بی‌معنی می‌ساخت. کلیپ‌شدنِ
        // بصریِ نهایی در renderBanner() با CSS انجام می‌شود.
        $clean = function ($value): string {
            $text = preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '';

            return trim($text);
        };

        $headline = $clean($raw['headline'] ?? '');
        $description = $clean($raw['description'] ?? '');
        $cta = $clean($raw['cta'] ?? '');

        return [
            'headline' => $headline !== '' ? $headline : $siteName,
            'description' => $description !== '' ? $description : ($niche ? trim($niche) : ''),
            'cta' => $cta !== '' ? $cta : (ContentLanguage::isPersian($language)
                ? 'مشاهده سایت'
                : ((string) parse_url($siteUrl, PHP_URL_HOST) ?: $siteName)),
        ];
    }

    /**
     * برشِ کاراکتریِ multibyte-safe — فقط برایِ کنترلِ حجمِ متنی که به‌عنوانِ
     * ورودی (niche/summary) به مدل فرستاده می‌شود، تا هزینه/طولِ پرامپت
     * دست‌وپاگیر نشود. عمداً به متنِ خروجیِ AI (headline/description/cta)
     * اعمال نمی‌شود — نگاه کنید به normalizeCopy().
     */
    protected function truncateForPrompt(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > $max * 0.5) {
            $cut = mb_substr($cut, 0, $space);
        }

        return (preg_replace('/[\s،,.:;\-]+$/u', '', $cut) ?? $cut).'…';
    }

    // ---------------------------------------------------------------
    // پرامپت‌ها و schema
    // ---------------------------------------------------------------

    protected function systemPrompt(bool $needColors): string
    {
        $prompt = <<<PROMPT
You write short ad copy in natural, fluent Persian (Farsi) for a business's own website banner. Reply with JSON only.
- headline: 2-4 words. The brand name plus a promise, or the main customer benefit. No emojis, quotes or trailing punctuation.
- description: one short, plain sentence saying what the business offers.
- cta: 1-2 words, an action (e.g. مشاهده خدمات، دریافت مشاوره).
- Use only facts from the input. Never invent discounts, numbers, awards or claims.
- Advertise the business itself, never an article.
- Keep every field short and to the point, but ALWAYS write it as a complete, natural, grammatically finished phrase or sentence.
- Never end a field with an ellipsis ("…" or "...") and never cut a word or sentence in half. Never leave a field hanging on a conjunction or preposition (e.g. ending in "و", "با", "در", "برای", "که").
- Do not worry about a strict character count. If a shorter, complete phrase reads better, use it — but never truncate a longer one to force it to be short.
PROMPT;

        if ($needColors) {
            $prompt .= "\n- primary, secondary: two #RRGGBB colors that fit the business niche. primary is vivid, secondary is a clearly distinct hue or shade (not a near-duplicate of primary). Neither may be near-white or near-black.";
        }

        return $prompt;
    }

    protected function buildUserPrompt($site, bool $needColors): string
    {
        $angles = [
            'lead with the main benefit for the customer',
            'lead with trust and expertise',
            'lead with a clear, direct invitation to act',
        ];

        $lines = ['Business name: '.$this->siteName($site)];

        if ($site->niche) {
            $lines[] = 'Niche: '.$this->truncateForPrompt(trim((string) $site->niche), 120);
        }

        if ($site->summary) {
            $summary = preg_replace('/\s+/u', ' ', strip_tags((string) $site->summary)) ?? '';
            $lines[] = 'About: '.$this->truncateForPrompt(trim($summary), 400);
        }

        $lines[] = 'Copy angle: '.$angles[array_rand($angles)];

        if ($needColors) {
            $lines[] = 'No logo colors are available, so also return the two brand colors.';
        }

        return implode("\n", $lines);
    }

    protected function bannerSchema(bool $needColors): array
    {
        $properties = [
            'headline' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'cta' => ['type' => 'string'],
        ];

        if ($needColors) {
            $properties['primary'] = ['type' => 'string'];
            $properties['secondary'] = ['type' => 'string'];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    // ---------------------------------------------------------------
    // زیرساختِ صدا زدنِ AI
    // ---------------------------------------------------------------

    protected function chat(
        string $model,
        string $system,
        string $user,
        string $schemaName,
        array $schema,
        int $timeout = 60,
        int $maxTokens = 1200,
        string $reasoningEffort = 'minimal',
    ): array {
        $startedAt = microtime(true);

        Log::info('ai.chat.request', [
            'model' => $model,
            'schema' => $schemaName,
            'timeout' => $timeout,
            'system_length' => mb_strlen($system),
            'user_length' => mb_strlen($user),
        ]);

        $payload = [
            'model' => $model,
            'max_completion_tokens' => $maxTokens,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if (preg_match('/^(gpt-5|o\d)/i', $model)) {
            $payload['reasoning_effort'] = $reasoningEffort;
        } else {
            $payload['temperature'] = 0.9;
        }

        try {
            $response = $this->callGapgptChat($payload, $timeout);
        } catch (Throwable $e) {
            Log::error('ai.chat.http_exception', [
                'model' => $model,
                'schema' => $schemaName,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if (! $response || ! $response->successful()) {
            Log::error('ai.chat.request_failed', [
                'model' => $model,
                'schema' => $schemaName,
                'status' => $response?->status(),
                'duration_ms' => $durationMs,
                'body' => Str::limit((string) $response?->body(), 2000),
            ]);

            throw new RuntimeException('AI request failed: '.($response?->body() ?? 'No active API key responded.'));
        }

        $message = $response->json('choices.0.message.content');
        $parsed = is_string($message) ? json_decode($message, true) : null;

        if (! is_array($parsed)) {
            Log::error('ai.chat.unparsable_response', [
                'model' => $model,
                'schema' => $schemaName,
                'duration_ms' => $durationMs,
                'finish_reason' => $response->json('choices.0.finish_reason'),
                'raw' => Str::limit((string) $message, 2000),
            ]);

            throw new RuntimeException('The AI response couldn\'t be processed.');
        }

        Log::info('ai.chat.response', [
            'model' => $model,
            'schema' => $schemaName,
            'duration_ms' => $durationMs,
            'usage' => $response->json('usage'),
            'finish_reason' => $response->json('choices.0.finish_reason'),
        ]);

        $usage = $response->json('usage');
        if ($usage && app()->bound('current_ai_user')) {
            $this->recordAiTokenUsage(
                user: app('current_ai_user'),
                serviceName: "ai.{$schemaName}",
                usage: $usage,
                model: $model,
            );
        }

        return $parsed;
    }
}
