<?php

namespace App\Support;

/**
 * English sentences describing the Auto Pilot schedule (API response).
 * The front builds the same text per UI language in AgentSettingsPanel.tsx.
 */
class AutopilotScheduleText
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** Still used by Persian-only helpers (content audit for Persian sites). */
    public static function toPersianDigits(int|string $value): string
    {
        return strtr((string) $value, array_combine(range(0, 9), self::PERSIAN_DIGITS));
    }

    /** "every day", "every other day", "every week" or "every 3 days". */
    public static function intervalPhrase(int $days): string
    {
        return match (true) {
            $days <= 1 => 'every day',
            $days === 2 => 'every other day',
            $days === 7 => 'every week',
            default => "every {$days} days",
        };
    }

    /** Short preview under the form, e.g. "📅 Your plan: 3 articles every 3 days". */
    public static function shortPreview(int $articleCount, int $intervalDays): string
    {
        return sprintf('📅 Your plan: %d article%s %s', $articleCount, $articleCount === 1 ? '' : 's', self::intervalPhrase($intervalDays));
    }

    /** Full sentence explaining every setting together. */
    public static function fullDescription(int $articleCount, int $intervalDays, int $startHour, string $publishStatus): string
    {
        $publishLabel = $publishStatus === 'publish'
            ? 'publishes them automatically'
            : 'saves them as drafts for you to review and publish';

        return sprintf(
            'Auto Pilot starts at %02d:00 and, %s, writes %d article%s and %s.',
            $startHour,
            self::intervalPhrase($intervalDays),
            $articleCount,
            $articleCount === 1 ? '' : 's',
            $publishLabel
        );
    }
}
