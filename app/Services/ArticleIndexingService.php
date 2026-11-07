<?php

namespace App\Services;

use App\Exceptions\SearchConsoleQuotaException;
use App\Models\Article;
use App\Models\ArticleIndexCheck;
use App\Models\ProductArticle;
use App\Models\Site;
use App\Models\SiteSearchConsoleProperty;
use App\Models\WordpressArticle;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * بررسی ایندکس شدن مقاله‌های تولیدشده در گوگل با URL Inspection API (فقط برای ادمین).
 *
 * زمان‌بندی بررسی دوباره، تا سهمیه‌ی روزانه‌ی گوگل (۲۰۰۰ در روز برای هر property) هدر نرود:
 * - هرگز بررسی‌نشده: اول از همه
 * - ایندکس‌نشده: هر ۲۴ ساعت (تا وقتی ایندکس شود)
 * - ایندکس‌شده: هر ۷ روز (برای اینکه اگر از ایندکس خارج شد معلوم شود)
 */
class ArticleIndexingService
{
    public const MAX_PER_RUN = 100;

    public const RECHECK_NOT_INDEXED_HOURS = 24;

    public const RECHECK_INDEXED_DAYS = 7;

    /** فاصله‌ی بین دو درخواست (میکروثانیه)؛ زیر سقف ۶۰۰ در دقیقه. */
    protected const PAUSE_MICROSECONDS = 200_000;

    public function __construct(protected GoogleSearchConsoleService $searchConsole) {}

    /**
     * فهرست مقاله‌های منتشرشده‌ی سایت را با جدول هماهنگ می‌کند: مقاله‌ی تازه اضافه، آدرس/عنوان عوض‌شده
     * به‌روز، و مقاله‌ای که دیگر منتشرشده نیست حذف می‌شود.
     */
    public function syncSubjects(Site $site): int
    {
        // مقاله‌ای که یوتیکس پیش‌نویس فرستاده و کاربر بعداً در خود وردپرس منتشرش کرده، پیش ما «draft» می‌ماند؛
        // ولی در پست‌های منتشرشده‌ی سایت (site_wordpress_articles) هست، پس آدرس و تاریخ انتشار از همان‌جا.
        $wpPublished = WordpressArticle::where('site_id', $site->id)->whereNotNull('url')
            ->get(['wp_post_id', 'url', 'published_at'])
            ->keyBy('wp_post_id');

        $published = fn ($query) => $query->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('publish_status', 'published')
                ->where(fn ($q) => $q->whereNotNull('wp_permalink')->orWhereNotNull('shopify_url')))
            ->orWhereIn('wp_post_id', $wpPublished->keys()->all()));
        $columns = ['id', 'title', 'wp_permalink', 'shopify_url', 'published_at', 'publish_status', 'wp_post_id'];

        $subjects = $published(Article::where('website_id', $site->id))->get($columns)
            ->map(fn (Article $a) => $this->subject(ArticleIndexCheck::TYPE_ARTICLE, $a, $wpPublished))
            ->concat($published(ProductArticle::where('site_id', $site->id))->get($columns)
                ->map(fn (ProductArticle $a) => $this->subject(ArticleIndexCheck::TYPE_PRODUCT_ARTICLE, $a, $wpPublished)))
            ->filter(fn (array $s) => $s['url'] !== '')
            ->values();

        foreach ($subjects as $subject) {
            $row = ArticleIndexCheck::firstOrNew(['subject_type' => $subject['subject_type'], 'subject_id' => $subject['subject_id']]);

            // آدرس عوض شد → نتیجه‌ی قبلی دیگر معتبر نیست و دوباره بررسی می‌شود
            if ($row->exists && $row->url !== $subject['url']) {
                $row->fill(['is_indexed' => null, 'verdict' => null, 'coverage_state' => null, 'checked_at' => null, 'error' => null]);
            }

            $row->fill(['site_id' => $site->id, 'title' => $subject['title'], 'url' => $subject['url'], 'published_at' => $subject['published_at']])->save();
        }

        $keep = $subjects->map(fn ($s) => $s['subject_type'].':'.$s['subject_id'])->all();

        ArticleIndexCheck::where('site_id', $site->id)->get(['id', 'subject_type', 'subject_id'])
            ->reject(fn (ArticleIndexCheck $c) => in_array($c->subject_type.':'.$c->subject_id, $keep, true))
            ->each->delete();

        return $subjects->count();
    }

    /** @return Collection<int, ArticleIndexCheck> مقاله‌هایی که نوبت بررسی‌شان رسیده؛ بررسی‌نشده‌ها اول */
    public function due(Site $site, int $limit = self::MAX_PER_RUN): Collection
    {
        return ArticleIndexCheck::where('site_id', $site->id)
            ->where(fn ($q) => $q->whereNull('checked_at')
                ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('is_indexed')->orWhere('is_indexed', false))
                    ->where('checked_at', '<', now()->subHours(self::RECHECK_NOT_INDEXED_HOURS)))
                ->orWhere(fn ($q) => $q->where('is_indexed', true)->where('checked_at', '<', now()->subDays(self::RECHECK_INDEXED_DAYS))))
            ->orderByRaw('checked_at is not null')
            ->orderBy('checked_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{tracked:int, checked:int, indexed:int, errors:int, quota_reached:bool}
     */
    public function checkProperty(SiteSearchConsoleProperty $property, int $limit = self::MAX_PER_RUN): array
    {
        $property->loadMissing(['site', 'connection']);
        $tracked = $this->syncSubjects($property->site);
        $stats = ['tracked' => $tracked, 'checked' => 0, 'indexed' => 0, 'errors' => 0, 'quota_reached' => false];

        foreach ($this->due($property->site, $limit) as $check) {
            if (! GoogleSearchConsoleService::urlBelongsToProperty($property->property_url, $check->url)) {
                $this->record($check, ['error' => 'This article\'s URL is not under the Search Console property ('.$property->property_url.').']);
                $stats['errors']++;

                continue;
            }

            try {
                $result = $this->searchConsole->inspectUrl($property, $check->url);
            } catch (SearchConsoleQuotaException) {
                $stats['quota_reached'] = true;

                break;
            } catch (Throwable $e) {
                $this->record($check, ['error' => Str::limit($e->getMessage(), 1000)]);
                $stats['errors']++;

                continue;
            }

            $indexed = ($result['verdict'] ?? null) === 'PASS';
            $this->record($check, [
                'is_indexed' => $indexed,
                'verdict' => $result['verdict'],
                'coverage_state' => Str::limit((string) $result['coverage_state'], 190, ''),
                'indexing_state' => $result['indexing_state'],
                'robots_txt_state' => $result['robots_txt_state'],
                'page_fetch_state' => $result['page_fetch_state'],
                'last_crawl_at' => $result['last_crawl_time'] ? Carbon::parse($result['last_crawl_time']) : null,
                'google_canonical' => $result['google_canonical'],
                'inspection_link' => $result['inspection_link'],
                'first_indexed_at' => $indexed ? ($check->first_indexed_at ?? now()) : $check->first_indexed_at,
                'error' => null,
            ]);

            $stats['checked']++;
            $stats['indexed'] += $indexed ? 1 : 0;
            usleep(self::PAUSE_MICROSECONDS);
        }

        return $stats;
    }

    protected function record(ArticleIndexCheck $check, array $values): void
    {
        $check->update($values + ['checked_at' => now(), 'checks_count' => $check->checks_count + 1]);
    }

    protected function subject(string $type, Article|ProductArticle $article, Collection $wpPublished): array
    {
        $post = $article->publish_status !== 'published' ? $wpPublished->get($article->wp_post_id) : null;
        $publishedAt = $post?->published_at ?? $article->published_at;

        return [
            'subject_type' => $type,
            'subject_id' => (string) $article->id,
            'title' => Str::limit((string) $article->title, 250, ''),
            'url' => (string) ($post?->url ?? SearchConsoleInsightsService::publicUrl($article)),
            'published_at' => $publishedAt ? Carbon::parse($publishedAt) : null,
        ];
    }
}
