<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleRefresh;
use App\Models\ProductArticle;
use App\Models\Site;
use App\Models\SiteSearchConsoleProperty;
use App\Support\PageUrl;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * هرچه از داده‌ی سرچ کنسولِ ذخیره‌شده ساخته می‌شود، بدون هیچ فراخوانی AI و بدون هزینه‌ی کریدیت:
 * نتیجه‌ی مقاله‌ها، فرصت‌های به‌روزرسانی، شواهد برای ساخت عنوان و سیگنال لینک داخلی.
 */
class SearchConsoleInsightsService
{
    /** مقاله‌ی تازه منتشرشده هنوز جایگاه ثابتی در گوگل ندارد. */
    public const MIN_ARTICLE_AGE_DAYS = 21;

    /** بعد از هر به‌روزرسانی، گوگل باید فرصت کند صفحه را دوباره بسنجد. */
    public const REFRESH_COOLDOWN_DAYS = 45;

    /** کمتر از این نمایش در ۲۸ روز، داده برای تصمیم کافی نیست. */
    public const MIN_IMPRESSIONS = 30;

    /** نرخ کلیک تقریبی هر رتبه در نتایج گوگل؛ فقط برای تخمین «کلیک ازدست‌رفته». */
    protected const EXPECTED_CTR = [1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.02];

    public function property(Site $site): ?SiteSearchConsoleProperty
    {
        return SiteSearchConsoleProperty::where('site_id', $site->id)->first();
    }

    public static function expectedCtr(float $position): float
    {
        return self::EXPECTED_CTR[max(1, (int) round($position))] ?? 0.01;
    }

    /**
     * آمار هر صفحه بر اساس page_hash. نسخه‌های مختلف یک آدرس (http/https، با/بدون www) یک کلید دارند
     * و جمع می‌شوند؛ رتبه با وزن نمایش میانگین گرفته می‌شود.
     *
     * @return Collection<string, array{clicks:int, previous_clicks:?int, impressions:int, previous_impressions:?int, ctr:float, position:?float, previous_position:?float}>
     */
    public function pageMetrics(Site $site): Collection
    {
        return DB::table('search_console_page_metrics')
            ->where('site_id', $site->id)
            ->get(['page_hash', 'clicks', 'previous_clicks', 'impressions', 'previous_impressions', 'position', 'previous_position'])
            ->groupBy('page_hash')
            ->map(function (Collection $rows) {
                $impressions = (int) $rows->sum('impressions');
                $clicks = (int) $rows->sum('clicks');
                $hasPrevious = $rows->contains(fn ($r) => $r->previous_impressions !== null);
                $previousImpressions = $hasPrevious ? (int) $rows->sum('previous_impressions') : null;

                return [
                    'clicks' => $clicks,
                    'previous_clicks' => $hasPrevious ? (int) $rows->sum('previous_clicks') : null,
                    'impressions' => $impressions,
                    'previous_impressions' => $previousImpressions,
                    'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
                    'position' => $this->weightedPosition($rows, 'position', 'impressions'),
                    'previous_position' => $hasPrevious ? $this->weightedPosition($rows, 'previous_position', 'previous_impressions') : null,
                ];
            });
    }

    /**
     * نتیجه‌ی مقاله‌های منتشرشده‌ی همین سرویس (مقاله و مقاله‌ی محصول) در گوگل.
     *
     * @return Collection<int, array>
     */
    public function articlePerformance(Site $site): Collection
    {
        $metrics = $this->pageMetrics($site);
        $topQueries = $this->topQueryByPage($site);

        // WordPress keeps the URL in wp_permalink, Shopify in shopify_url
        $published = fn ($query) => $query->where('publish_status', 'published')
            ->where(fn ($q) => $q->whereNotNull('wp_permalink')->orWhereNotNull('shopify_url'));
        $columns = ['id', 'title', 'wp_permalink', 'shopify_url', 'published_at'];

        $articles = $published(Article::where('website_id', $site->id))
            ->get($columns)
            ->map(fn (Article $a) => ['type' => 'article', 'id' => $a->id, 'title' => $a->title, 'url' => self::publicUrl($a), 'published_at' => self::date($a->published_at)]);

        $productArticles = $published(ProductArticle::where('site_id', $site->id))
            ->get($columns)
            ->map(fn (ProductArticle $a) => ['type' => 'product_article', 'id' => $a->id, 'title' => $a->title, 'url' => self::publicUrl($a), 'published_at' => self::date($a->published_at)]);

        return $articles->concat($productArticles)
            ->map(function (array $row) use ($metrics, $topQueries) {
                $hash = PageUrl::hash($row['url']);
                $m = $hash ? $metrics->get($hash) : null;

                return $row + [
                    'page_hash' => $hash,
                    'has_data' => $m !== null,
                    'clicks' => $m['clicks'] ?? 0,
                    'previous_clicks' => $m['previous_clicks'] ?? null,
                    'impressions' => $m['impressions'] ?? 0,
                    'previous_impressions' => $m['previous_impressions'] ?? null,
                    'ctr' => $m['ctr'] ?? 0.0,
                    'position' => $m['position'] ?? null,
                    'previous_position' => $m['previous_position'] ?? null,
                    'top_query' => $hash ? ($topQueries[$hash] ?? null) : null,
                ];
            })
            ->sortByDesc(fn ($r) => [$r['clicks'], $r['impressions']])
            ->values();
    }

    /**
     * دلیل پیشنهاد به‌روزرسانی یک مقاله، یا null. «کلیک ازدست‌رفته» تخمین کلیک‌های اضافه در ۲۸ روز است.
     *
     * @return array{reason:string, potential_clicks:int}|null
     */
    public function refreshReason(array $row): ?array
    {
        $impressions = (int) $row['impressions'];
        $clicks = (int) $row['clicks'];
        $position = $row['position'];
        $previousClicks = $row['previous_clicks'];

        if ($previousClicks !== null && $previousClicks >= 10 && $clicks <= $previousClicks * 0.7) {
            return ['reason' => ArticleRefresh::REASON_DECLINING, 'potential_clicks' => $previousClicks - $clicks];
        }

        if ($position === null || $impressions < self::MIN_IMPRESSIONS) {
            return null;
        }

        if ($position >= 8 && $position <= 20) {
            return ['reason' => ArticleRefresh::REASON_NEAR_FIRST_PAGE, 'potential_clicks' => max(1, (int) round($impressions * self::expectedCtr(5)) - $clicks)];
        }

        $expected = self::expectedCtr($position);

        if ($position < 8 && $impressions >= 100 && ($clicks / $impressions) < $expected * 0.5) {
            return ['reason' => ArticleRefresh::REASON_LOW_CTR, 'potential_clicks' => max(1, (int) round($impressions * $expected) - $clicks)];
        }

        return null;
    }

    /**
     * مقاله‌هایی که به‌روزرسانی‌شان بیشترین کلیک را برمی‌گرداند (فقط مقاله‌ی عادی؛ ساختار مقاله‌ی محصول فرق دارد).
     *
     * @return Collection<int, array>
     */
    public function refreshCandidates(Site $site, ?Collection $performance = null, int $limit = 10): Collection
    {
        $performance ??= $this->articlePerformance($site);

        $blocked = ArticleRefresh::where('site_id', $site->id)
            ->where(fn ($q) => $q->whereIn('status', ArticleRefresh::OPEN_STATUSES)
                ->orWhere(fn ($q) => $q->where('status', ArticleRefresh::STATUS_APPLIED)
                    ->where('applied_at', '>=', now()->subDays(self::REFRESH_COOLDOWN_DAYS))))
            ->pluck('article_id')
            ->all();

        return $performance
            ->filter(fn ($r) => $r['type'] === 'article'
                && $r['has_data']
                && ! in_array($r['id'], $blocked, true)
                && $r['published_at'] !== null
                && $r['published_at']->lte(now()->subDays(self::MIN_ARTICLE_AGE_DAYS)))
            ->map(fn ($r) => ($reason = $this->refreshReason($r)) ? $r + $reason : null)
            ->filter()
            ->sortByDesc('potential_clicks')
            ->take($limit)
            ->values();
    }

    /**
     * کوئری‌هایی که یک صفحه در گوگل برایشان دیده می‌شود (پرنمایش‌ترین اول).
     *
     * @return Collection<int, array{query:string, clicks:int, impressions:int, ctr:float, position:?float}>
     */
    public function queriesForPage(Site $site, string $pageHash, int $limit = 15): Collection
    {
        return DB::table('search_console_query_metrics')
            ->where('site_id', $site->id)
            ->where('page_hash', $pageHash)
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get(['query', 'clicks', 'impressions', 'ctr', 'position'])
            ->map(fn ($r) => [
                'query' => (string) $r->query,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'ctr' => round((float) $r->ctr, 4),
                'position' => $r->position !== null ? round((float) $r->position, 1) : null,
            ]);
    }

    /**
     * شواهد سرچ کنسول برای ساخت عنوان مقاله: جستجوهایی که سایت در آن‌ها دیده می‌شود ولی هیچ صفحه‌اش
     * در صفحه‌ی اول نیست (فرصت مقاله‌ی جدید) و جستجوهایی که از قبل قوی است (تکرار نشود).
     *
     * @param  array<int, string>  $coveredTexts  عنوان/کلمه‌ی کلیدی مقاله‌های موجود، برای حذف فرصت تکراری
     */
    public function ideaEvidenceBlock(Site $site, array $coveredTexts = [], int $limit = 25): string
    {
        $rows = $this->aggregatedQueries($site);

        if ($rows->isEmpty()) {
            return '';
        }

        $opportunities = $this->queryOpportunities($site, $coveredTexts, $limit, $rows);
        $strong = $rows->filter(fn ($r) => $r->best_position <= 5)->take(12);
        $competing = $this->competingPages($site, 15);

        if ($opportunities->isEmpty() && $strong->isEmpty() && $competing->isEmpty()) {
            return '';
        }

        $lines = ["Google Search Console — real searches from this site's own data (last 28 days). Never invent volume or numbers."];

        if ($opportunities->isNotEmpty()) {
            $lines[] = "A) Opportunities: people search these and the site appears in Google, but none of its pages is on the first page (best position > 8). Build ideas around these exact searches first, keeping the searcher's own wording in the title; one idea may cover several close searches.";

            foreach ($opportunities as $r) {
                $lines[] = sprintf('- %s | impressions: %d | best position: %.1f', $r->query, $r->impressions, $r->best_position);
            }
        }

        if ($strong->isNotEmpty()) {
            $lines[] = 'B) Already strong (a page of the site already ranks in the top 5): do NOT create new ideas targeting these same searches.';

            foreach ($strong as $r) {
                $lines[] = '- '.$r->query;
            }
        }

        if ($competing->isNotEmpty()) {
            $lines[] = 'C) Already contested: two or more pages of this site already compete in Google for these searches (keyword cannibalization). A new article would be a third competitor and weaken all of them: do NOT create ideas for these searches.';

            foreach ($competing as $c) {
                $lines[] = '- '.$c['query'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * جستجوهایی که سایت در گوگل برایشان دیده می‌شود ولی هیچ صفحه‌اش در صفحه‌ی اول نیست و مقاله‌ای هم برایشان ننوشته:
     * بهترین موضوع مقاله‌ی تازه، چون تقاضای واقعی و اعتماد اولیه‌ی گوگل هر دو هست.
     *
     * @param  array<int, string>  $coveredTexts  عنوان/کلمه‌ی کلیدی مقاله‌های موجود
     * @return Collection<int, object{query:string, impressions:int, clicks:int, best_position:float}>
     */
    public function queryOpportunities(Site $site, array $coveredTexts = [], int $limit = 25, ?Collection $rows = null): Collection
    {
        $covered = array_values(array_filter(array_map(fn ($t) => self::normalizeText((string) $t), $coveredTexts)));

        return ($rows ?? $this->aggregatedQueries($site))
            ->filter(fn ($r) => $r->best_position > 8 && $r->impressions >= 10)
            ->reject(fn ($r) => $this->isCovered(self::normalizeText($r->query), $covered))
            ->take($limit)
            ->values();
    }

    /** عنوان و کلمه‌ی کلیدی مقاله‌هایی که سایت دارد، تا جستجوی پوشش‌داده‌شده «فرصت تازه» حساب نشود. */
    public function coveredTexts(Site $site): array
    {
        return Article::where('website_id', $site->id)
            ->where('status', '!=', Article::STATUS_FAILED)
            ->latest('id')
            ->limit(300)
            ->get(['title', 'focus_keyword'])
            ->flatMap(fn ($a) => [$a->title, $a->focus_keyword])
            ->concat(\App\Models\WordpressArticle::where('site_id', $site->id)->latest('id')->limit(300)->pluck('title'))
            ->filter()
            ->all();
    }

    /**
     * رقابت داخلی (keyword cannibalization): جستجوهایی که دو یا چند صفحه‌ی همین سایت برایشان در گوگل دیده می‌شوند.
     * فقط صفحه‌ای حساب می‌شود که سهم واقعی دارد (حداقل ۱۵٪ نمایش‌های آن جستجو و رتبه‌ی بهتر از ۳۰).
     * صفحه‌ی اول هر ردیف «صفحه‌ی اصلی» است (بیشترین کلیک، بعد بیشترین نمایش).
     *
     * @return Collection<int, array{query:string, impressions:int, question:bool, pages: array<int, array{hash:string, page:string, impressions:int, clicks:int, position:?float}>}>
     */
    public function competingPages(Site $site, int $limit = 20): Collection
    {
        return DB::table('search_console_query_metrics')
            ->where('site_id', $site->id)
            ->whereNotNull('page_hash')
            ->selectRaw('query, page_hash, MAX(page) as page, SUM(impressions) as impressions, SUM(clicks) as clicks, MIN(position) as position')
            ->groupBy('query', 'page_hash')
            ->orderByDesc('impressions')
            ->limit(5000)
            ->get()
            ->groupBy('query')
            ->map(function (Collection $rows, $query) {
                $total = (int) $rows->sum('impressions');
                $pages = $rows
                    ->filter(fn ($r) => (int) $r->impressions >= max(5, $total * 0.15) && $r->position !== null && (float) $r->position <= 30)
                    ->sortBy([['clicks', 'desc'], ['impressions', 'desc']])
                    ->map(fn ($r) => [
                        'hash' => (string) $r->page_hash,
                        'page' => (string) $r->page,
                        'impressions' => (int) $r->impressions,
                        'clicks' => (int) $r->clicks,
                        'position' => round((float) $r->position, 1),
                    ])
                    ->values()
                    ->all();

                return ['query' => (string) $query, 'impressions' => $total, 'question' => self::isQuestion((string) $query), 'pages' => $pages];
            })
            ->filter(fn ($c) => count($c['pages']) >= 2 && $c['impressions'] >= self::MIN_IMPRESSIONS)
            ->sortByDesc('impressions')
            ->take($limit)
            ->values();
    }

    /**
     * جستجوهای این صفحه که صفحه‌ی اصلی‌شان صفحه‌ی دیگری از همین سایت است (این صفحه رقیب ضعیف‌تر است).
     *
     * @return array<string, string> جستجو => آدرس صفحه‌ی اصلی
     */
    public function competitorsForPage(Site $site, ?string $pageHash): array
    {
        if (! $pageHash) {
            return [];
        }

        $out = [];
        foreach ($this->competingPages($site, 300) as $c) {
            $hashes = array_column($c['pages'], 'hash');

            if (in_array($pageHash, $hashes, true) && $hashes[0] !== $pageHash) {
                $out[$c['query']] = $c['pages'][0]['page'];
            }
        }

        return $out;
    }

    /** جستجوی سؤالی (چطور، چرا، چیست، ...): بهترین منبع برای پرسش‌های متداول و پاراگراف جواب‌محور. */
    public static function isQuestion(string $query): bool
    {
        $q = ' '.self::normalizeText($query).' ';

        return (bool) preg_match('/[?؟]|\s(چطور|چطوری|چگونه|چرا|چیست|چیه|چی|کدام|کدوم|آیا|چقدر|چند|کجا|چه|how|what|why|which|when|where|can|does|is)\s/u', $q);
    }

    /**
     * جستجوهای واقعی فروشگاه که به محصولات انتخاب‌شده ربط دارند، برای عنوان مقاله‌ی محصول.
     *
     * @param  array<int, string>  $names  نام محصولات و دسته‌ها
     */
    public function productIdeaEvidenceBlock(Site $site, array $names, int $limit = 12): string
    {
        $tokens = collect($names)
            ->flatMap(fn ($n) => preg_split('/[^\p{L}\p{N}]+/u', self::normalizeText((string) $n), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn ($t) => mb_strlen($t) >= 3 && ! in_array($t, ['مدل', 'برای', 'همراه', 'اصل', 'رنگ', 'سری', 'with', 'for', 'the', 'and'], true))
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return '';
        }

        $matches = $this->aggregatedQueries($site)
            ->filter(function ($r) use ($tokens) {
                $query = ' '.self::normalizeText($r->query).' ';

                return $tokens->contains(fn ($t) => str_contains($query, $t));
            })
            ->take($limit);

        if ($matches->isEmpty()) {
            return '';
        }

        return "Real Google searches on this store related to these products (Search Console, last 28 days). When one fits a style, prefer its exact wording for focus_keyword and the title:\n"
            .$matches->map(fn ($r) => sprintf('- %s | impressions: %d | best position: %.1f', $r->query, $r->impressions, $r->best_position))->implode("\n");
    }

    /**
     * برای لینک داخلی: هر صفحه در گوگل برای چه جستجوهایی دیده می‌شود و میانگین رتبه‌اش چند است.
     *
     * @return array<string, array{queries: array<int, string>, position: ?float}>
     */
    public function pageQueryIndex(Site $site, int $perPage = 5): array
    {
        $metrics = $this->pageMetrics($site);

        return DB::table('search_console_query_metrics')
            ->where('site_id', $site->id)
            ->whereNotNull('page_hash')
            ->orderByDesc('impressions')
            ->limit(5000)
            ->get(['page_hash', 'query'])
            ->groupBy('page_hash')
            ->map(fn (Collection $rows, string $hash) => [
                'queries' => $rows->pluck('query')->map(fn ($q) => (string) $q)->unique()->take($perPage)->values()->all(),
                'position' => $metrics->get($hash)['position'] ?? null,
            ])
            ->all();
    }

    /** نرمال‌سازی ساده برای مقایسه‌ی جستجو با متن (ی/ک عربی، نیم‌فاصله، حروف کوچک). */
    public static function normalizeText(string $text): string
    {
        $text = strtr($text, ['ي' => 'ی', 'ك' => 'ک', "\u{200c}" => ' ', 'ۀ' => 'ه', 'ة' => 'ه']);

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /** @return Collection<int, object{query:string, impressions:int, clicks:int, best_position:float}> */
    protected function aggregatedQueries(Site $site): Collection
    {
        return DB::table('search_console_query_metrics')
            ->where('site_id', $site->id)
            ->selectRaw('query, SUM(impressions) as impressions, SUM(clicks) as clicks, MIN(position) as best_position')
            ->groupBy('query')
            ->orderByDesc('impressions')
            ->limit(300)
            ->get()
            ->map(function ($r) {
                $r->impressions = (int) $r->impressions;
                $r->clicks = (int) $r->clicks;
                $r->best_position = (float) ($r->best_position ?? 100);

                return $r;
            });
    }

    /** جستجویی که کامل در عنوان یا کلمه‌ی کلیدی یک مقاله‌ی موجود آمده، پوشش داده شده است. */
    protected function isCovered(string $query, array $covered): bool
    {
        foreach ($covered as $text) {
            if (str_contains($text, $query)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> page_hash => پرنمایش‌ترین جستجوی آن صفحه */
    public function topQueryByPage(Site $site): array
    {
        return DB::table('search_console_query_metrics')
            ->where('site_id', $site->id)
            ->whereNotNull('page_hash')
            ->orderByDesc('impressions')
            ->limit(5000)
            ->get(['page_hash', 'query'])
            ->unique('page_hash')
            ->pluck('query', 'page_hash')
            ->map(fn ($q) => (string) $q)
            ->all();
    }

    /** Public URL of the article (WordPress or Shopify). */
    public static function publicUrl(Article|ProductArticle $article): ?string
    {
        return $article->wp_permalink ?: $article->shopify_url;
    }

    protected static function date(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    protected function weightedPosition(Collection $rows, string $positionKey, string $weightKey): ?float
    {
        $weighted = $rows->filter(fn ($r) => $r->{$positionKey} !== null && (int) $r->{$weightKey} > 0);
        $weight = $weighted->sum(fn ($r) => (int) $r->{$weightKey});

        if ($weight === 0) {
            return null;
        }

        return round($weighted->sum(fn ($r) => (float) $r->{$positionKey} * (int) $r->{$weightKey}) / $weight, 1);
    }
}
