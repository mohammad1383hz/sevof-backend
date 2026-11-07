<?php

namespace App\Services\AI;

use App\Models\ContentResearchSession;
use App\Models\EntityKeyword;
use App\Models\Site;
use App\Models\User;
use App\Services\JustSerpApiClient;
use App\Support\ContentLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Deterministic content research based on the user's Search Console data and
 * live Google SERP metadata returned by JustSerp.
 *
 * With GSC connected, observed metrics are used. Without GSC, assistant estimates
 * are generated from site signals (EntityKeyword, or as a last resort the raw
 * Site::keywords column) and live SERP metadata.
 * Competitor domains are always extracted from SERP; competitor pages are never crawled.
 */
class ContentResearchService
{
    use RecordsTokenUsage, \App\Services\AI\Concerns\UsesGapgptApiKey;

    public const TYPE_KEYWORD_CLUSTERS = 'keyword_clusters';
    private const DATA_SOURCE = 'gsc_justserp';
    private const DATA_SOURCE_ENTITY = 'assistant_justserp';
    private const DATA_SOURCE_SITE_KEYWORDS = 'assistant_site_keywords';

    public function run(ContentResearchSession $session): void
    {
        $session->load(['site.entity', 'site.user']);
        $site = $session->site;

        if (! $site || ! $site->ensureEntity()) {
            throw new RuntimeException('The site analysis isn\'t ready yet. Try again in a minute or two.');
        }

        $context = $this->buildContext($site, $session->prompt);

        if ($session->type !== self::TYPE_KEYWORD_CLUSTERS) {
            throw new RuntimeException('This service only builds keyword clusters.');
        }

        $this->storeKeywordClusters($session, $site, $this->generateKeywordClusters($site, $context));

        $session->update([
            'status' => 'done',
            'data_source' => $this->dataSourceFor($site),
            'error' => null,
            'completed_at' => now(),
        ]);
    }

    protected function buildContext(Site $site, ?string $prompt): string
    {
        $entity = $site->entity;
        $keywords = EntityKeyword::query()
            ->where('site_id', $site->id)
            ->orderByDesc('total_relevance')
            ->limit(15)
            ->get(['entity_scope', 'entity_name', 'base_keyword', 'total_relevance', 'source_variants'])
            ->map(fn (EntityKeyword $keyword) => [
                'scope' => $keyword->entity_scope,
                'entity' => $keyword->entity_name,
                'keyword' => $keyword->base_keyword,
                'relevance' => $keyword->total_relevance,
                'variants' => $keyword->source_variants,
            ])
            ->all();

        $searchConsoleQueries = $this->researchQueries($site)
            ->take(30)
            ->map(fn (object $metric) => $this->metricPayload($metric))
            ->all();

        return implode("\n", [
            "Domain: {$site->domain}",
            "Niche: {$site->niche}",
            'Primary Entity: '.json_encode($entity->primary_entity, JSON_UNESCAPED_UNICODE),
            'Core Entities: '.json_encode($entity->core_entities, JSON_UNESCAPED_UNICODE),
            'Keyword seeds from this site: '.json_encode($keywords, JSON_UNESCAPED_UNICODE),
            'Observed query signals (Google Search Console when connected, otherwise stored site keyword signals): '.json_encode($searchConsoleQueries, JSON_UNESCAPED_UNICODE),
            'User request: '.($prompt ?: 'تحقیق بر اساس داده‌های موجود سایت و نتایج Google'),
        ]);
    }

    protected function generateKeywordClusters(Site $site, string $context): array
    {
        $queries = $this->researchQueries($site);
        if ($queries->isEmpty()) {
            throw new RuntimeException('Complete the site analysis and keyword data before building clusters.');
        }

        // وقتی GSC متصل نیست، AI از EntityKeyword (یا در نبود آن، از
        // Site::keywords) و SERP واقعی برای برآورد مفید استفاده می‌کند.
        if ($this->searchConsoleQueries($site)->isEmpty()) {
            return $this->generateAssistantKeywordClusters($site, $context, $queries);
        }

        $groups = $queries
            ->groupBy(fn (object $row) => $this->clusterKey((string) $row->query))
            ->sortByDesc(fn (Collection $rows) => $rows->sum('impressions'))
            ->take(8);

        $serp = JustSerpApiClient::fromConfig();
        $clusters = [];

        foreach ($groups as $name => $rows) {
            $rows = collect($rows)->sortByDesc('impressions')->values();
            $primary = $rows->first();
            if (! $primary) {
                continue;
            }

            $serpRows = $serp->search((string) $primary->query, $this->serpOptions($site));
            $competitorCount = collect($serpRows)
                ->map(fn (array $result) => $this->hostname($result['link']))
                ->filter()
                ->unique()
                ->count();

            $clusters[] = [
                'name' => $name,
                'primary_keyword' => (string) $primary->query,
                'related_keywords' => $rows->pluck('query')->unique()->reject(fn ($query) => $query === $primary->query)->take(6)->values()->all(),
                'search_intent' => $this->intentFor((string) $primary->query),
                'estimated_search_volume' => (int) $rows->sum('impressions'),
                'volume_label' => 'observed_impressions',
                'trend_direction' => $this->trendDirection($rows),
                'trend_percentage' => $this->trendPercentage($rows),
                'difficulty' => 'unavailable',
                'opportunity_score' => $this->opportunityScore($primary),
                'competitor_count' => $competitorCount,
                'why_now' => 'بر پایه Impression و Position واقعی Search Console و نتایج زنده JustSerp ساخته شده است.',
                'evidence' => [
                    'source' => self::DATA_SOURCE,
                    'query_metrics' => $rows->map(fn (object $row) => $this->metricPayload($row))->all(),
                    'serp_sample' => array_slice($serpRows, 0, 5),
                    'trend_note' => 'برای درصد رشد/افت باید حداقل دو بازه مستقل Search Console sync شود؛ مقدار ساختگی تولید نشده است.',
                ],
            ];
        }

        if (empty($clusters)) {
            throw new RuntimeException('No usable clusters were found in the Search Console data.');
        }

        return $clusters;
    }

    protected function generateAssistantKeywordClusters(Site $site, string $context, Collection $queries): array
    {
        $serpSignals = [];
        try {
            $serp = JustSerpApiClient::fromConfig();
            foreach ($queries->take(8) as $query) {
                $serpSignals[] = [
                    'query' => (string) $query->query,
                    'results' => array_slice($serp->search((string) $query->query, $this->serpOptions($site)), 0, 5),
                ];
            }
        } catch (\Throwable $exception) {
            // برآورد keyword بدون JustSerp هم ممکن است؛ در این حالت فقط سیگنال سایت و AI استفاده می‌شود.
            $serpSignals = [];
        }

        $response = $this->callGapgptChat([
            'model' => 'gpt-5-nano',
            // 'temperature' => 0.2,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'assistant_keyword_clusters',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'clusters' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'primary_keyword' => ['type' => 'string'],
                                        'related_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                                        'search_intent' => ['type' => 'string', 'enum' => ['informational', 'commercial', 'transactional', 'navigational']],
                                        'estimated_search_volume' => ['type' => 'integer', 'minimum' => 0],
                                        'difficulty_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                                        'opportunity_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                                        'reason' => ['type' => 'string'],
                                    ],
                                    'required' => ['name', 'primary_keyword', 'related_keywords', 'search_intent', 'estimated_search_volume', 'difficulty_score', 'opportunity_score', 'reason'],
                                ],
                            ],
                        ],
                        'required' => ['clusters'],
                    ],
                ],
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => ContentLanguage::localize('شما متخصص سئو هستید. وقتی Google Search Console متصل نیست، از Entityها، keyword seedهای ذخیره‌شده و شواهد SERP داده‌شده، چند خوشه keyword مفید تولید کن. Volume و difficulty را فقط به‌عنوان برآورد دستیار بده، نه داده قطعی Google. عددها باید محافظه‌کارانه و بین 0 تا 1000000 برای volume و 0 تا 100 برای difficulty باشند. keyword جدید فقط وقتی مجاز است که با موضوع سایت و seedها مرتبط باشد. فقط JSON مطابق schema برگردان.', ContentLanguage::forSite($site)),
                ],
                [
                    'role' => 'user',
                    'content' => $context."\n\nSeed signals:\n".json_encode($queries->take(30)->map(fn (object $row) => $this->metricPayload($row))->all(), JSON_UNESCAPED_UNICODE)."\n\nLive SERP signals:\n".json_encode($serpSignals, JSON_UNESCAPED_UNICODE),
                ],
            ],
        ], 120);

        if (! $response->successful()) {
            Log::error('ContentResearch: assistant keyword cluster AI request failed', [
                'site_id' => $site->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Smart keyword estimation failed.');
        }

        $usage = $response->json('usage');

        // --- فیکس اصلی ---
        // $site->user ممکن است null برگردد چون این relation فقط زمانی درست
        // resolve می‌شود که رابطه‌ی user() روی مدل Site به‌درستی تعریف شده
        // و user_id مقدار داشته باشد. قبلاً اگر این شرط false می‌شد، مصرف
        // AI بی‌سروصدا و بدون هیچ لاگی از دست می‌رفت با وجود اینکه هزینه‌ی
        // واقعی متحمل شده بود. حالا با resolveBillableUser یک تلاش مطمئن‌تر
        // (شامل fallback به query مستقیم روی user_id) انجام می‌شود و هر
        // مسیر شکست صراحتاً لاگ می‌شود.
        $billableUser = $this->resolveBillableUser($site);

        if ($usage && $billableUser) {
            $this->recordAiTokenUsage(
                user: $billableUser,
                serviceName: 'ai.keyword_cluster_estimation',
                usage: $usage,
                model: 'gpt-5-nano',
                metadata: ['site_id' => $site->id],
            );
        } elseif ($usage) {
            Log::error('ContentResearch: AI usage returned but no billable user resolved; credit NOT deducted', [
                'site_id' => $site->id,
                'site_user_id_column' => $site->user_id ?? null,
                'usage' => $usage,
            ]);
        } else {
            Log::warning('ContentResearch: AI response had no usage field', [
                'site_id' => $site->id,
            ]);
        }

        $items = $response->json('choices.0.message.content');
        $data = is_string($items) ? json_decode($items, true) : $items;
        $clusters = is_array($data['clusters'] ?? null) ? $data['clusters'] : [];
        $serpByQuery = collect($serpSignals)->keyBy('query');

        $result = [];
        foreach ($clusters as $cluster) {
            $primary = trim((string) ($cluster['primary_keyword'] ?? ''));
            if ($primary === '') {
                continue;
            }

            $serpRows = $serpByQuery->get($primary, ['results' => []])['results'] ?? [];
            $difficulty = max(0, min(100, (int) ($cluster['difficulty_score'] ?? 50)));
            $volume = max(0, min(1000000, (int) ($cluster['estimated_search_volume'] ?? 0)));
            $result[] = [
                'name' => trim((string) ($cluster['name'] ?? $this->clusterKey($primary))),
                'primary_keyword' => $primary,
                'related_keywords' => collect($cluster['related_keywords'] ?? [])->filter(fn ($item) => is_string($item) && trim($item) !== '')->map(fn (string $item) => trim($item))->unique()->take(8)->values()->all(),
                'search_intent' => in_array($cluster['search_intent'] ?? '', ['informational', 'commercial', 'transactional', 'navigational'], true) ? $cluster['search_intent'] : $this->intentFor($primary),
                'estimated_search_volume' => $volume,
                'volume_label' => 'assistant_estimate',
                'trend_direction' => 'estimated',
                'trend_percentage' => null,
                'difficulty' => 'estimated:'.$difficulty,
                'opportunity_score' => max(0, min(100, (int) ($cluster['opportunity_score'] ?? 50))),
                'competitor_count' => collect($serpRows)->map(fn (array $row) => $this->hostname($row['link'] ?? ''))->filter()->unique()->count(),
                'why_now' => (string) ($cluster['reason'] ?? 'Estimated from the assistant\'s analysis and site signals.'),
                'evidence' => [
                    'source' => $this->dataSourceFor($site),
                    'estimation_note' => 'حجم و سختی برآورد دستیار هستند و از Google Search Console یا Keyword Planner نیامده‌اند.',
                    'seed_signals' => $queries->take(10)->map(fn (object $row) => $this->metricPayload($row))->all(),
                    'serp_sample' => array_slice($serpRows, 0, 5),
                ],
            ];
        }

        if ($result === []) {
            throw new RuntimeException('The AI did not produce a valid cluster.');
        }

        return array_slice($result, 0, 8);
    }

    protected function serpOptions(Site $site): array
    {
        $language = ContentLanguage::forSite($site);

        return ['language' => $language, 'country' => ContentLanguage::country($language)];
    }

    /**
     * کاربر قابل‌شارژ برای مصرف AI مربوط به یک سایت را resolve می‌کند.
     * اول از relation لودشده استفاده می‌کند؛ اگر خالی بود (مثلاً به این
     * دلیل که site بدون eager-load وارد شده)، یک تلاش مستقیم روی
     * user_id هم انجام می‌دهد تا هیچ مصرفی به‌خاطر یک relation خالی
     * بی‌صدا از دست نرود.
     */
    protected function resolveBillableUser(Site $site): ?User
    {
        if ($site->relationLoaded('user') && $site->user) {
            return $site->user;
        }

        if (! $site->relationLoaded('user')) {
            // تلاش برای لیزی-لود کردن relation
            $user = $site->user;
            if ($user) {
                return $user;
            }
        }

        // آخرین fallback: مستقیم بر اساس user_id کوئری بزن، شاید relation
        // به هر دلیلی (اسم ستون متفاوت، cache، و...) درست resolve نشده باشد.
        if (! empty($site->user_id)) {
            $user = User::find($site->user_id);
            if ($user) {
                return $user;
            }
        }

        return null;
    }

  protected function storeKeywordClusters(ContentResearchSession $session, Site $site, array $clusters): void
{
    DB::transaction(function () use ($session, $site, $clusters) {
        foreach ($clusters as $cluster) {
            $primaryKeyword = trim($cluster['primary_keyword']);
            $normalizedKeyword = mb_strtolower($primaryKeyword);

            $payload = [
                'content_research_session_id' => $session->id,
                'site_id' => $site->id,
                'name' => $cluster['name'],
                'primary_keyword' => $primaryKeyword,
                'related_keywords' => json_encode($cluster['related_keywords'], JSON_UNESCAPED_UNICODE),
                'search_intent' => $cluster['search_intent'],
                'estimated_search_volume' => $cluster['estimated_search_volume'],
                'volume_label' => $cluster['volume_label'],
                'trend_direction' => $cluster['trend_direction'],
                'trend_percentage' => $cluster['trend_percentage'] === null ? 0 : (int) $cluster['trend_percentage'],
                'difficulty' => $cluster['difficulty'],
                'opportunity_score' => $cluster['opportunity_score'],
                'competitor_count' => $cluster['competitor_count'],
                'data_source' => $this->dataSourceFor($site),
                'evidence' => json_encode($cluster['evidence'], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ];

            // ⬅️ جلوگیری از تکرار (case-insensitive + بدون حساسیت به فاصله‌ی اضافه):
            // مقایسه روی LOWER(TRIM(primary_keyword)) انجام می‌شود تا کلیدواژه‌هایی
            // که فقط در بزرگی/کوچکی حروف یا فاصله‌ی ابتدا/انتها فرق دارند
            // (مثلاً "SEO محتوا" و "seo محتوا " یا " SEO محتوا") یکی حساب شوند.
            $existing = DB::table('keyword_clusters')
                ->where('site_id', $site->id)
                ->whereRaw('LOWER(TRIM(primary_keyword)) = ?', [$normalizedKeyword])
                ->first(['id']);

            if ($existing) {
                DB::table('keyword_clusters')
                    ->where('id', $existing->id)
                    ->update($payload);
            } else {
                DB::table('keyword_clusters')->insert($payload + ['created_at' => now()]);
            }
        }
    });
}

    protected function searchConsoleQueries(Site $site): Collection
    {
        return DB::table('search_console_query_metrics')
            ->where('site_id', $site->id)
            ->orderByDesc('impressions')
            ->orderByDesc('clicks')
            ->limit(100)
            ->get();
    }

    protected function entityKeywordQueries(Site $site): Collection
    {
        return EntityKeyword::query()
            ->where('site_id', $site->id)
            ->orderByDesc('total_relevance')
            ->limit(100)
            ->get(['base_keyword', 'total_relevance'])
            ->map(fn (EntityKeyword $keyword) => (object) [
                'query' => $keyword->base_keyword,
                'clicks' => 0,
                'impressions' => (int) ($keyword->total_relevance ?? 0),
                'ctr' => 0.0,
                'position' => null,
                'previous_impressions' => null,
                'trend_percentage' => null,
                'start_date' => null,
                'end_date' => null,
                'source' => 'entity_keywords',
            ]);
    }

    /**
     * سومین و آخرین fallback: وقتی نه Search Console وصل است و نه
     * EntityKeyword برای این سایت تولید شده (مثلاً keyword_analysis_status
     * هنوز "not_started" است)، از ستون خام Site::keywords که هنگام
     * idea generation / کراول اولیه سایت ساخته شده استفاده می‌کنیم تا
     * کاربر مجبور به تکرار مراحل قبلی نشود.
     *
     * چون این کلمات relevance عددی ندارند، به‌جای جعل عدد impressions،
     * فقط یک وزن نزولی بر اساس ترتیب قرارگیری در آرایه اختصاص می‌دهیم
     * (فرض بر این‌که اقلام اول لیست، اولویت/ربط بیشتری داشته‌اند).
     */
    protected function siteKeywordQueries(Site $site): Collection
    {
        $rawKeywords = $site->keywords;
        $keywords = is_string($rawKeywords) ? json_decode($rawKeywords, true) : $rawKeywords;

        if (! is_array($keywords)) {
            return collect();
        }

        $keywords = collect($keywords)
            ->filter(fn ($keyword) => is_string($keyword) && trim($keyword) !== '')
            ->map(fn (string $keyword) => trim($keyword))
            ->unique()
            ->values();

        $total = $keywords->count();

        return $keywords->map(function (string $keyword, int $index) use ($total) {
            $weight = max(1, $total - $index);

            return (object) [
                'query' => $keyword,
                'clicks' => 0,
                'impressions' => $weight,
                'ctr' => 0.0,
                'position' => null,
                'previous_impressions' => null,
                'trend_percentage' => null,
                'start_date' => null,
                'end_date' => null,
                'source' => 'site_keywords',
            ];
        });
    }

    protected function researchQueries(Site $site): Collection
    {
        $searchConsoleQueries = $this->searchConsoleQueries($site);
        if ($searchConsoleQueries->isNotEmpty()) {
            return $searchConsoleQueries;
        }

        $entityKeywordQueries = $this->entityKeywordQueries($site);
        if ($entityKeywordQueries->isNotEmpty()) {
            return $entityKeywordQueries;
        }

        return $this->siteKeywordQueries($site);
    }

    protected function dataSourceFor(Site $site): string
    {
        if ($this->searchConsoleQueries($site)->isNotEmpty()) {
            return self::DATA_SOURCE; // gsc_justserp
        }

        if ($this->entityKeywordQueries($site)->isNotEmpty()) {
            return self::DATA_SOURCE_ENTITY; // assistant_justserp
        }

        return self::DATA_SOURCE_SITE_KEYWORDS; // assistant_site_keywords
    }

    protected function clusterKey(string $query): string
    {
        $tokens = preg_split('/\s+/u', trim(mb_strtolower($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = ['از', 'به', 'در', 'برای', 'با', 'و', 'یا', 'که', 'را', 'یک'];
        $tokens = array_values(array_filter($tokens, fn (string $token) => ! in_array($token, $stopWords, true)));

        return implode(' ', array_slice($tokens, 0, 3)) ?: trim($query);
    }

    protected function hostname(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $host = parse_url(str_starts_with($url, 'http') ? $url : 'https://'.$url, PHP_URL_HOST);
        $host = is_string($host) ? mb_strtolower($host) : '';

        return preg_replace('/^www\./', '', $host) ?: '';
    }

    protected function intentFor(string $query): string
    {
        $query = mb_strtolower($query);
        if (preg_match('/(خرید|قیمت|هزینه|سفارش|فروش)/u', $query)) {
            return 'transactional';
        }
        if (preg_match('/(مقایسه|بهترین|بررسی|نظرات)/u', $query)) {
            return 'commercial';
        }
        if (preg_match('/(ورود|آدرس|تماس|سایت)/u', $query)) {
            return 'navigational';
        }

        return 'informational';
    }

    protected function opportunityScore(object $metric): int
    {
        $impressions = max(0, (int) ($metric->impressions ?? 0));
        $position = max(1, (float) ($metric->position ?? 20));
        $score = (log10($impressions + 1) * 18) + max(0, 30 - $position);

        return min(100, max(0, (int) round($score)));
    }

    protected function trendPercentage(Collection $rows): ?int
    {
        $current = (int) $rows->sum('impressions');
        $previous = (int) $rows->sum('previous_impressions');

        return $previous > 0 ? (int) round((($current - $previous) / $previous) * 100) : null;
    }

    protected function trendDirection(Collection $rows): string
    {
        $trend = $this->trendPercentage($rows);
        if ($trend === null) {
            return 'unavailable';
        }

        return $trend > 2 ? 'up' : ($trend < -2 ? 'down' : 'stable');
    }

    protected function metricPayload(object $metric): array
    {
        return [
            'query' => (string) $metric->query,
            'clicks' => (int) $metric->clicks,
            'impressions' => (int) $metric->impressions,
            'ctr' => (float) $metric->ctr,
            'position' => $metric->position !== null ? (float) $metric->position : null,
            'previous_impressions' => $metric->previous_impressions !== null ? (int) $metric->previous_impressions : null,
            'trend_percentage' => $metric->trend_percentage !== null ? (int) $metric->trend_percentage : null,
            'start_date' => $metric->start_date ?? null,
            'end_date' => $metric->end_date ?? null,
            // فیکس: قبلاً این مقدار همیشه به‌صورت هارد‌کد 'google_search_console'
            // برمی‌گشت، حتی وقتی متریک واقعاً از entity_keywords یا
            // Site::keywords آمده بود. این باعث می‌شد evidence نمایش‌داده‌شده
            // به کاربر منبع داده را اشتباه گزارش کند (شفافیت منبع، هدف اصلی
            // این سرویس است). حالا از $metric->source که هر سه fallback
            // (searchConsoleQueries / entityKeywordQueries / siteKeywordQueries)
            // آن را ست می‌کنند استفاده می‌شود.
            'source' => $metric->source ?? 'google_search_console',
        ];
    }
}
