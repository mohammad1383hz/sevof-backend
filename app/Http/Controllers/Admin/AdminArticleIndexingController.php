<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CheckArticleIndexingJob;
use App\Models\ArticleIndexCheck;
use App\Models\Site;
use App\Models\SiteSearchConsoleProperty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** وضعیت ایندکس مقاله‌های تولیدشده در گوگل، برای سایت‌های متصل به سرچ کنسول (فقط ادمین). */
class AdminArticleIndexingController extends Controller
{
    public const STATUSES = ['indexed', 'not_indexed', 'unchecked', 'error'];

    public function index(Request $request)
    {
        $siteId = $request->integer('site_id') ?: null;
        $status = in_array($request->string('status')->toString(), self::STATUSES, true) ? $request->string('status')->toString() : null;

        $checks = ArticleIndexCheck::query()
            ->with('site:id,domain')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($status, fn ($q) => match ($status) {
                'indexed' => $q->where('is_indexed', true),
                'not_indexed' => $q->where('is_indexed', false),
                'unchecked' => $q->whereNull('checked_at'),
                'error' => $q->whereNotNull('error'),
            })
            ->orderByRaw('is_indexed is null, is_indexed asc')
            ->latest('published_at')
            ->paginate(30)
            ->withQueryString();

        // جمع کل و به تفکیک سایت
        $bySite = ArticleIndexCheck::query()
            ->selectRaw('site_id, count(*) as total')
            ->selectRaw('sum(case when is_indexed = 1 then 1 else 0 end) as indexed')
            ->selectRaw('sum(case when is_indexed = 0 then 1 else 0 end) as not_indexed')
            ->selectRaw('sum(case when checked_at is null then 1 else 0 end) as unchecked')
            ->selectRaw('sum(case when error is not null then 1 else 0 end) as errors')
            ->selectRaw('max(checked_at) as last_checked_at')
            ->groupBy('site_id')
            ->get()
            ->keyBy('site_id');

        $properties = SiteSearchConsoleProperty::query()
            ->with(['site:id,domain,user_id', 'site.user:id,name,email', 'connection:id,status'])
            ->get()
            ->filter(fn ($p) => $p->site !== null)
            ->sortByDesc(fn ($p) => (int) ($bySite[$p->site_id]->total ?? 0))
            ->values();

        $totals = [
            'total' => (int) $bySite->sum('total'),
            'indexed' => (int) $bySite->sum('indexed'),
            'not_indexed' => (int) $bySite->sum('not_indexed'),
            'unchecked' => (int) $bySite->sum('unchecked'),
            'errors' => (int) $bySite->sum('errors'),
        ];

        // میانگین روز تا ایندکس (از انتشار تا اولین باری که ایندکس دیده شد)
        $avgDays = ArticleIndexCheck::whereNotNull('first_indexed_at')->whereNotNull('published_at')
            ->get(['published_at', 'first_indexed_at'])
            ->avg(fn ($c) => max(0, $c->published_at->diffInDays($c->first_indexed_at)));

        // دلیل‌های ایندکس‌نشدن (و خطاها) با تعداد، برای سایت انتخاب‌شده یا همه
        $reasons = ArticleIndexCheck::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where(fn ($q) => $q->where('is_indexed', false)->orWhere(fn ($q) => $q->whereNull('is_indexed')->whereNotNull('error')))
            ->get(['is_indexed', 'error', 'coverage_state', 'indexing_state', 'robots_txt_state', 'page_fetch_state', 'published_at'])
            ->map->reason()
            ->filter()
            ->groupBy('title')
            ->map(fn ($group, $title) => [
                'title' => $title,
                'fix' => $group->first()['fix'],
                'count' => $group->count(),
                'new' => $group->where('is_new', true)->count(),
            ])
            ->sortByDesc('count')
            ->values();

        $selectedSite = $siteId ? $properties->firstWhere('site_id', $siteId) : null;

        return view('admin.indexing.index', compact('checks', 'bySite', 'properties', 'totals', 'avgDays', 'siteId', 'status', 'reasons', 'selectedSite'));
    }

    /** همان کار زمان‌بندی‌شده‌ی ۵:۳۰، الان: همه‌ی سایت‌های متصل با فاصله در صف background می‌روند. */
    public function checkAll(): RedirectResponse
    {
        Artisan::call('search-console:check-indexing');

        return back()->with('success', trim(Artisan::output()).' Each site is checked 20 seconds after the previous one; refresh the page in a few minutes.');
    }

    public function check(Site $site): RedirectResponse
    {
        $property = SiteSearchConsoleProperty::where('site_id', $site->id)->with('connection')->first();

        if (! $property || $property->connection?->status !== 'connected') {
            return back()->with('error', "{$site->domain} is not connected to Search Console.");
        }

        CheckArticleIndexingJob::dispatch($property->id);

        return back()->with('success', "Index check for {$site->domain} articles is queued. Refresh the page in a few minutes.");
    }
}
