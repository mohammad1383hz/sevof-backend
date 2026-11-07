<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ProductArticle;
use App\Models\Site;
use App\Models\WordpressArticle;
use App\Support\PageUrl;
use Illuminate\Support\Collection;

/**
 * صفحه‌هایی از همین سایت که مقاله‌ی در حال به‌روزرسانی می‌تواند به آن‌ها لینک بدهد:
 * مقاله‌ها و مقاله‌های محصول منتشرشده‌ی یوتیکس + پست‌های خود سایت.
 * فهرست کوتاه با تطابق واژگانی ساخته می‌شود؛ انتخاب معنایی و جای لینک با AI است و کد دوباره بررسی‌اش می‌کند.
 */
class InternalLinkCandidates
{
    public const LIMIT = 12;

    protected const MIN_SCORE = 2;

    /** مقاله‌ی یوتیکس که در این مدت منتشر شده «تازه» است و کمی اولویت می‌گیرد. */
    protected const FRESH_DAYS = 120;

    public function __construct(protected RelatedArticleLinkService $text) {}

    /**
     * @param  string  $topic  عنوان، کلمه‌ی کلیدی، تیترها و جستجوهای مقاله
     * @param  array<int, string>  $linkedUrls  لینک‌هایی که مقاله از قبل دارد (دوباره پیشنهاد نمی‌شوند)
     * @return array<int, array{url:string, title:string, kind:string, fresh:bool}> شماره (از ۱) => صفحه
     */
    public function forPage(Site $site, ?string $selfUrl, string $topic, array $linkedUrls = []): array
    {
        $topicTokens = $this->tokens($topic);

        if ($topicTokens === []) {
            return [];
        }

        $skip = collect([$selfUrl, ...$linkedUrls])->map(fn ($u) => PageUrl::hash($u))->filter()->flip();
        $metrics = app(SearchConsoleInsightsService::class)->pageMetrics($site);

        $ranked = $this->pages($site)
            ->reject(fn (array $p) => $skip->has($p['hash']))
            ->map(function (array $p) use ($topicTokens, $metrics) {
                $score = count(array_intersect($topicTokens, $p['title_tokens'])) * 2
                    + count(array_intersect($topicTokens, $p['extra_tokens']));
                // صفحه‌ی رتبه‌ی ۴ تا ۲۰ بیشترین سود را از لینک داخلی می‌برد (فقط اگر خودش مرتبط باشد)
                $position = $metrics->get($p['hash'])['position'] ?? null;
                $boost = ($p['fresh'] ? 1 : 0) + ($position !== null && $position >= 4 && $position <= 20 ? 1 : 0);

                return $p + ['score' => $score > 0 ? $score + $boost : $score];
            })
            ->filter(fn (array $p) => $p['score'] >= self::MIN_SCORE)
            ->sortByDesc('score')
            ->take(self::LIMIT)
            ->values();

        $out = [];
        foreach ($ranked as $i => $p) {
            $out[$i + 1] = ['url' => $p['url'], 'title' => $p['title'], 'kind' => $p['kind'], 'fresh' => $p['fresh']];
        }

        return $out;
    }

    /** @return Collection<int, array{hash:string, url:string, title:string, kind:string, fresh:bool, title_tokens:array, extra_tokens:array}> */
    protected function pages(Site $site): Collection
    {
        $freshSince = now()->subDays(self::FRESH_DAYS);
        $published = fn ($q) => $q->where('publish_status', 'published')
            ->where(fn ($w) => $w->whereNotNull('wp_permalink')->orWhereNotNull('shopify_url'));

        $youtix = $published(Article::where('website_id', $site->id))
            ->get(['id', 'title', 'focus_keyword', 'wp_permalink', 'shopify_url', 'published_at'])
            ->map(fn (Article $a) => $this->page(SearchConsoleInsightsService::publicUrl($a), $a->title, 'youtix', $a->published_at >= $freshSince, (string) $a->focus_keyword));

        $products = $published(ProductArticle::where('site_id', $site->id))
            ->get(['id', 'title', 'wp_permalink', 'shopify_url', 'published_at'])
            ->map(fn (ProductArticle $a) => $this->page(SearchConsoleInsightsService::publicUrl($a), $a->title, 'product', $a->published_at >= $freshSince));

        // پستی که در آخرین خواندن از سایت دیده نشد (حذف یا پیش‌نویس شده) لینک داده نمی‌شود
        $posts = WordpressArticle::where('site_id', $site->id)
            ->where(fn ($q) => $q->whereNotNull('managed')->orWhereNull('plugin_synced_at'))
            ->get(['title', 'url', 'categories', 'tags'])
            ->map(fn (WordpressArticle $p) => $this->page($p->url, (string) $p->title, 'post', false, collect([...(array) $p->categories, ...(array) $p->tags])->pluck('name')->implode(' ')));

        // یک صفحه با چند منبع: نسخه‌ی یوتیکس (اطلاعات کامل‌تر) نگه داشته می‌شود
        return $youtix->concat($products)->concat($posts)
            ->filter(fn ($p) => $p !== null)
            ->unique('hash')
            ->values();
    }

    protected function page(?string $url, ?string $title, string $kind, bool $fresh, string $extra = ''): ?array
    {
        $hash = PageUrl::hash($url);
        $title = trim(html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! $hash || $title === '') {
            return null;
        }

        return [
            'hash' => $hash,
            'url' => (string) $url,
            'title' => $title,
            'kind' => $kind,
            'fresh' => $fresh,
            'title_tokens' => $this->tokens($title),
            'extra_tokens' => $this->tokens($extra),
        ];
    }

    /** @return array<int, string> */
    protected function tokens(string $text): array
    {
        return $this->text->meaningfulTokens($this->text->normalizePersianText($text));
    }
}
