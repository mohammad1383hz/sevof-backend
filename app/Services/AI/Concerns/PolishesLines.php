<?php

namespace App\Services\AI\Concerns;

use App\Services\AI\ChatModelResolver;
use App\Support\PersianText;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * بازنویسی ارزان و هدفمند متن فارسی: فقط خط‌های مشکل‌دار (پاراگراف یا آیتم لیست) با مدل ارزان
 * بازنویسی و با کد سر جایشان گذاشته می‌شوند؛ بقیه‌ی متنِ مدل اصلی دست نمی‌خورد.
 * هزینه ≈ چند خط ورودی و خروجی با gpt-4o-mini، نه بازنویسی کل بخش با مدل اصلی.
 */
trait PolishesLines
{
    protected const POLISH_MAX_LINES = 8;

    /**
     * @param  array<int, string>  $issues  توضیح مشکل‌ها برای مدل
     * @param  callable(string): bool  $lineHasIssue
     * @param  bool  $includeFirstLine  مثلاً وقتی عبارت کلیدی در ابتدای مقدمه نیامده
     * @param  bool  $persian  فقط متن فارسی با PersianText نرمال می‌شود (ی/ک عربی برای سایت عربی غلط است)
     */
    protected function polishLines(string $content, array $issues, callable $lineHasIssue, bool $includeFirstLine = false, bool $persian = true): string
    {
        $lines = explode("\n", $content);

        // تیتر و جدول هرگز بازنویسی نمی‌شوند
        $body = array_filter($lines, fn ($l) => ($t = trim($l)) !== '' && ! str_starts_with($t, '#') && ! str_starts_with($t, '|'));
        $targets = array_filter($body, $lineHasIssue);

        if ($includeFirstLine && $body !== []) {
            $first = array_key_first($body);
            $targets[$first] = $body[$first];
        }

        if ($targets === []) {
            return $content;
        }

        ksort($targets);
        $targets = array_slice($targets, 0, self::POLISH_MAX_LINES, true);

        try {
            $result = $this->chat(
                model: ChatModelResolver::FREE_API_MODEL,
                // chat() سرویس محصول این متن را به زبان سایت localize می‌کند؛ مقاله‌ی عادی فقط برای فارسی صدا می‌زند
                system: 'You are a senior Persian copy editor. Each numbered line is one paragraph or list item of a Persian article. Fix ONLY the listed problems: replace every cliché or empty phrase with concrete wording or simply remove it; if the focus keyword is missing, put it exactly as given, naturally, into the first line; if the keyword is over-used, keep it only where most natural and use a synonym elsewhere; split every over-long sentence the PROBLEMS mention into 2–3 short sentences where each follows from the previous one; replace every heavy word with the simple everyday word given. Keep meaning, facts, numbers, tone, form of address, Markdown (list markers, **bold**, [anchor](PLACEHOLDER) links) and every UPPERCASE placeholder exactly. The result must sound like natural human Persian and must not add new clichés. Return each given line once with its index in "lines".',
                user: "PROBLEMS:\n- ".implode("\n- ", $issues)."\n\nLINES:\n"
                    .implode("\n", array_map(fn ($i, $l) => "[{$i}] ".trim($l), array_keys($targets), $targets)),
                schemaName: 'polish_lines',
                schema: [
                    'type' => 'object',
                    'properties' => [
                        'lines' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => ['index' => ['type' => 'integer'], 'text' => ['type' => 'string']],
                                'required' => ['index', 'text'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['lines'],
                    'additionalProperties' => false,
                ],
                temperature: 0.5,
                timeout: 60,
                maxTokens: (int) max(800, array_sum(array_map('mb_strlen', $targets)) / 1.5 + 400),
            );
        } catch (Throwable $e) {
            Log::warning('ai.polish_lines.failed', ['error' => $e->getMessage()]);

            return $content;
        }

        $marker = '/^\s*(?:[-*+]|\d+[.)])\s+/u';

        foreach ((array) ($result['lines'] ?? []) as $item) {
            $i = $item['index'] ?? null;
            $new = trim((string) ($item['text'] ?? ''));
            $new = $persian ? PersianText::normalize($new) : $new;

            if (! is_int($i) || ! isset($targets[$i]) || $new === '') {
                continue;
            }

            $old = $lines[$i];
            $ratio = mb_strlen($new) / max(1, mb_strlen(trim($old)));
            preg_match_all('/\b[A-Z]+(?:_[A-Z0-9]+)+\b/', $old, $placeholders);
            $lost = array_filter($placeholders[0], fn ($ph) => ! str_contains($new, $ph));

            // خروجی مشکوک (کوتاه/بلند شدن زیاد یا گم شدن لینک) → خط اصلی می‌ماند
            if ($ratio < 0.5 || $ratio > 1.6 || $lost !== []) {
                continue;
            }

            // نشانه‌ی لیست و تورفتگی خط اصلی حفظ می‌شود
            preg_match($marker, $old, $prefix);
            $lines[$i] = ($prefix[0] ?? '').preg_replace($marker, '', $new);
        }

        return implode("\n", $lines);
    }
}
