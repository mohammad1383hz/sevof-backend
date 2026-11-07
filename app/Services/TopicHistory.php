<?php

namespace App\Services;

use App\Models\ArticleIdea;
use App\Models\ProductSeoIdea;
use App\Models\Site;
use App\Models\WordpressArticle;
use Illuminate\Support\Collection;

/**
 * موضوع‌هایی که سایت قبلاً دارد، تا ایده‌ی تازه (مقاله یا مقاله‌ی محصول) تکرار آن‌ها نباشد:
 * - چند مقاله‌ی آخر خود صاحب سایت به AI داده می‌شود (پرامپت سبک بماند)
 * - همه‌ی عنوان‌ها (پست‌های سایت + ایده‌های قبلی) برای فیلتر تکراری سمت سرور
 */
class TopicHistory
{
    /** تعداد مقاله‌های آخر خود سایت که به AI نشان داده می‌شود */
    public const RECENT_POSTS = 5;

    /** شباهت واژگانی (Jaccard) که از آن به بعد دو عنوان «همان موضوع» حساب می‌شوند */
    public const DUPLICATE_SIMILARITY = 0.7;

    public function __construct(protected RelatedArticleLinkService $text) {}

    /** @return Collection<int, string> عنوان آخرین مقاله‌های خود سایت (نه یوتیکس)، جدیدترین اول */
    public function recentSitePosts(Site $site, int $limit = self::RECENT_POSTS): Collection
    {
        return WordpressArticle::where('site_id', $site->id)
            ->where('managed', false)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('title')
            ->map(fn ($t) => trim(html_entity_decode((string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            ->filter()
            ->values();
    }

    public function recentPostsBlock(Site $site): string
    {
        $titles = $this->recentSitePosts($site);

        if ($titles->isEmpty()) {
            return '';
        }

        return "The site owner's own most recent articles (already published on the site; do NOT suggest a topic that answers the same question or a reworded version of them):\n"
            .$titles->map(fn ($t) => "- {$t}")->implode("\n");
    }

    /**
     * توکن عنوان همه‌ی چیزهایی که سایت دارد یا قبلاً پیشنهاد شده.
     *
     * @return array<int, array<int, string>>
     */
    public function existingTitleTokens(Site $site, bool $includeArticleIdeas = true, bool $includeProductIdeas = true): array
    {
        $titles = WordpressArticle::where('site_id', $site->id)->pluck('title');

        if ($includeArticleIdeas) {
            $titles = $titles->concat(ArticleIdea::where('website_id', $site->id)->pluck('title'));
        }

        if ($includeProductIdeas) {
            $titles = $titles->concat(ProductSeoIdea::where('site_id', $site->id)->pluck('title'));
        }

        return $titles->map(fn ($t) => $this->tokens((string) $t))->filter()->values()->all();
    }

    /** @param  array<int, array<int, string>>  $existing */
    public function isDuplicate(string $title, array $existing): bool
    {
        $tokens = $this->tokens($title);

        foreach ($existing as $other) {
            if ($this->similarity($tokens, $other) >= self::DUPLICATE_SIMILARITY) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function tokens(string $title): array
    {
        $normalized = $this->text->normalizePersianText(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $tokens = $this->text->meaningfulTokens($normalized);

        // عنوانی که همه‌اش کلمه‌ی عمومی است: کل متن نرمال‌شده ملاک باشد
        return $tokens ?: ($normalized === '' ? [] : [$normalized]);
    }

    /** Jaccard دو مجموعه توکن (۰ تا ۱) */
    public function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique([...$a, ...$b]));
    }
}
