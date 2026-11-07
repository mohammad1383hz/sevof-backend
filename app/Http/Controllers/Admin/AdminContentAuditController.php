<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AuditPastPostsJob;
use App\Models\ArticleRefresh;
use App\Models\Site;
use App\Models\WordpressArticle;
use App\Services\SearchConsoleInsightsService;
use App\Support\ContentAudit;
use App\Support\PageUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * گزارش کامل سئوی «مقاله‌های گذشته» برای ادمین: خلاصه‌ی هر سایت و جزئیات هر مقاله
 * (لینک داخلی، سال‌های قدیمی، تازگی، داده‌ی گوگل و تاریخچه‌ی آپدیت‌ها با لینک‌های تازه).
 * کاربر فقط ایرادها را می‌بیند و هیچ عدد صفری به او نشان داده نمی‌شود؛ این صفحه همه‌ی اعداد را دارد.
 */
class AdminContentAuditController extends Controller
{
    private const PER_PAGE = 50;

    public const FILTERS = [
        'needs_update' => 'Needs update',
        'no_internal_links' => 'No internal links',
        'weak_internal_links' => 'Few internal links',
        'outdated_year_title' => 'Outdated year in title',
        'outdated_year_text' => 'Outdated year in text',
        'stale' => 'Not updated (over a year)',
        'thin_content' => 'Thin content',
        'no_headings' => 'Untitled',
        'not_audited' => 'Not checked yet',
        'refreshed' => 'Updated',
    ];

    public function index(Request $request, SearchConsoleInsightsService $insights)
    {
        $siteId = $request->integer('site_id') ?: null;
        $filter = array_key_exists($request->string('filter')->toString(), self::FILTERS) ? $request->string('filter')->toString() : null;
        $search = trim($request->string('q')->toString());

        $sites = $this->siteSummaries();
        $selectedSite = $siteId ? Site::withTrashed()->with('user:id,name,email')->find($siteId) : null;
        $rows = collect();
        $issueCounts = [];

        if ($selectedSite) {
            $rows = $this->articleRows($selectedSite, $insights);
            $issueCounts = $rows->flatMap(fn ($r) => array_column($r['issues'], 'code'))->countBy()->all()
                + ['needs_update' => $rows->where('needs_update', true)->count(), 'not_audited' => $rows->where('audit', null)->count(), 'refreshed' => $rows->filter(fn ($r) => $r['refreshes']->isNotEmpty())->count()];

            $rows = $rows
                ->filter(fn ($r) => match ($filter) {
                    null => true,
                    'needs_update' => $r['needs_update'],
                    'not_audited' => $r['audit'] === null,
                    'refreshed' => $r['refreshes']->isNotEmpty(),
                    default => in_array($filter, array_column($r['issues'], 'code'), true),
                })
                ->filter(fn ($r) => $search === '' || mb_stripos((string) $r['post']->title, $search) !== false)
                ->values();
        }

        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('admin.content-audit.index', [
            'sites' => $sites,
            'totals' => [
                'posts' => $sites->sum('posts'),
                'audited' => $sites->sum('audited'),
                'needs_update' => $sites->sum('needs_update'),
                'no_links' => $sites->sum('no_links'),
                'new_links' => $sites->sum('new_links'),
                'applied' => $sites->sum('applied'),
            ],
            'selectedSite' => $selectedSite,
            'articles' => $paginator,
            'issueCounts' => $issueCounts,
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    /** بررسی دوباره‌ی همه‌ی پست‌های یک سایت در پس‌زمینه (مثلاً بعد از اضافه شدن لینک‌ها توسط خود کاربر). */
    public function audit(Site $site): RedirectResponse
    {
        WordpressArticle::where('site_id', $site->id)->where('managed', false)->update(['audited_at' => null]);
        AuditPastPostsJob::dispatch($site->id);

        return back()->with('success', "Re-check of {$site->domain} articles is queued; refresh the page in a few minutes.");
    }

    /** @return Collection<int, array> خلاصه‌ی هر سایتی که مقاله‌ی گذشته دارد، با بیشترین «نیاز به آپدیت» اول */
    private function siteSummaries(): Collection
    {
        $stats = [];

        WordpressArticle::where('managed', false)
            ->select(['id', 'site_id', 'title', 'published_at', 'modified_at', 'audit'])
            ->lazyById(500)
            ->each(function (WordpressArticle $post) use (&$stats) {
                $s = &$stats[$post->site_id];
                $s ??= ['posts' => 0, 'audited' => 0, 'needs_update' => 0, 'no_links' => 0, 'weak_links' => 0, 'outdated' => 0, 'stale' => 0, 'links_sum' => 0, 'words_sum' => 0, 'applied' => 0, 'new_links' => 0, 'credit' => 0.0];
                $audit = is_array($post->audit) ? $post->audit : null;
                $issues = ContentAudit::issues($audit, (string) $post->title, $post->modified_at ?? $post->published_at);
                $codes = array_column($issues, 'code');

                $s['posts']++;
                $s['needs_update'] += ContentAudit::needsUpdate($issues) ? 1 : 0;
                $s['outdated'] += array_intersect(['outdated_year_title', 'outdated_year_text'], $codes) !== [] ? 1 : 0;
                $s['stale'] += in_array('stale', $codes, true) ? 1 : 0;

                if ($audit !== null) {
                    $s['audited']++;
                    $s['no_links'] += in_array('no_internal_links', $codes, true) ? 1 : 0;
                    $s['weak_links'] += in_array('weak_internal_links', $codes, true) ? 1 : 0;
                    $s['links_sum'] += (int) ($audit['internal_links'] ?? 0);
                    $s['words_sum'] += (int) ($audit['words'] ?? 0);
                }
            });

        ArticleRefresh::where('source', ArticleRefresh::SOURCE_WP_POST)
            ->get(['site_id', 'status', 'after', 'credit_used'])
            ->each(function (ArticleRefresh $r) use (&$stats) {
                if (! isset($stats[$r->site_id])) {
                    return;
                }
                $stats[$r->site_id]['credit'] += (float) $r->credit_used;

                if ($r->status === ArticleRefresh::STATUS_APPLIED) {
                    $stats[$r->site_id]['applied']++;
                    $stats[$r->site_id]['new_links'] += count($r->after['links'] ?? []);
                }
            });

        $sites = Site::withTrashed()->with('user:id,name,email')->whereIn('id', array_keys($stats))->get()->keyBy('id');

        return collect($stats)
            ->map(fn (array $s, int $id) => $s + [
                'site' => $sites->get($id),
                'avg_links' => $s['audited'] > 0 ? round($s['links_sum'] / $s['audited'], 1) : null,
                'avg_words' => $s['audited'] > 0 ? (int) round($s['words_sum'] / $s['audited']) : null,
            ])
            ->filter(fn ($s) => $s['site'] !== null)
            ->sortByDesc('needs_update')
            ->values();
    }

    /** @return Collection<int, array> هر مقاله‌ی گذشته‌ی سایت با بررسی سئو، داده‌ی گوگل و همه‌ی آپدیت‌هایش */
    private function articleRows(Site $site, SearchConsoleInsightsService $insights): Collection
    {
        $metrics = $insights->pageMetrics($site);
        $refreshes = ArticleRefresh::where('site_id', $site->id)
            ->where('source', ArticleRefresh::SOURCE_WP_POST)
            ->latest('id')
            ->get(['id', 'wordpress_article_id', 'status', 'reason', 'trigger', 'summary', 'after', 'credit_used', 'error', 'created_at', 'applied_at'])
            ->groupBy('wordpress_article_id');

        return WordpressArticle::where('site_id', $site->id)
            ->where('managed', false)
            ->orderByDesc('published_at')
            ->get()
            ->map(function (WordpressArticle $post) use ($metrics, $refreshes, $insights) {
                $audit = is_array($post->audit) ? $post->audit : null;
                $issues = ContentAudit::issues($audit, (string) $post->title, $post->modified_at ?? $post->published_at);
                $gsc = $metrics->get(PageUrl::hash($post->url));
                $reason = $gsc && $post->editable ? ($insights->refreshReason($gsc)['reason'] ?? null) : null;

                return [
                    'post' => $post,
                    'audit' => $audit,
                    'recommended_links' => $audit ? ContentAudit::recommendedLinks((int) ($audit['words'] ?? 0)) : null,
                    'issues' => $issues,
                    'gsc' => $gsc,
                    'reason' => $reason,
                    'needs_update' => $reason !== null || ContentAudit::needsUpdate($issues),
                    'refreshes' => $refreshes->get($post->id, collect()),
                ];
            })
            // اول ایرادهای جدی، بعد بیشترین نمایش در گوگل
            ->sortBy([
                fn ($a, $b) => $b['needs_update'] <=> $a['needs_update'],
                fn ($a, $b) => ($b['gsc']['impressions'] ?? -1) <=> ($a['gsc']['impressions'] ?? -1),
            ])
            ->values();
    }
}
