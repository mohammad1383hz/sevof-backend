<?php

namespace App\Services\AI;

use App\Models\ContentResearchSession;
use App\Models\EntityKeyword;
use App\Models\Site;
use App\Services\AI\Concerns\UsesGapgptApiKey;
use App\Services\JustSerpApiClient;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * جایگزین سرویس Manus برای تحقیق شکاف رقبا.
 *
 * منطق کار عمداً بین «داده‌ی قطعی» و «نوشته‌ی روایی» تقسیم شده:
 *
 *  ۱. کلمات کلیدی سایت (GSC یا Entity Keywords) استخراج می‌شود.
 *  ۲. برای هر کلمه، نتایج واقعی گوگل از JustSerp خوانده می‌شود.
 *  ۳. تشخیصِ «رقیب واقعی کیست»، «رتبه چند است»، «شکاف کجاست» و امتیازها
 *     همگی در PHP و با محاسبه‌ی قطعی انجام می‌شود — نه حدس مدل.
 *  ۴. فقط برای همان شکاف‌های واقعاً پیداشده، gpt-5-nano صرفاً بخش نوشتاری
 *     فارسی (توضیح، زاویه‌ی محتوا، عنوان، آوت‌لاین، توصیه‌ها) را بر اساس
 *     همان عنوان/snippet واقعیِ SERP می‌نویسد؛ هیچ عدد یا تصمیمی به مدل
 *     واگذار نمی‌شود تا خروجی قابل اتکا و قابل ممیزی بماند.
 */
class CompetitorGapResearchService
{
    use RecordsTokenUsage, UsesGapgptApiKey;

    public const TYPE_COMPETITOR_GAPS = 'competitor_gaps';

    private const DATA_SOURCE = 'justserp_ai';
    private const AI_MODEL = 'gpt-5-nano';
    private const MAX_KEYWORDS = 12;
    private const MAX_COMPETITORS = 8;
    private const MAX_GAP_CANDIDATES = 15;
    private const MAX_SAMPLES_PER_COMPETITOR = 3;

    public function run(ContentResearchSession $session): void
    {
        $session->load(['site.entity', 'site.user', 'user']);

        // اگر این سشن قبلاً یک‌بار با موفقیت کامل شده باشد، دیگر نباید دوباره
        // به JustSerp یا AI درخواست بزنیم (مصرف اضافه‌ی کوتا و کلیدها).
        if ($session->status === 'done' && ! empty($session->metadata['research'] ?? null)) {
            Log::info('CompetitorGap: session already has a completed result, skipping re-fetch', [
                'session_id' => $session->id,
            ]);

            return;
        }

        $site = $session->site;

        if (! $site || ! $site->ensureEntity()) {
            throw new RuntimeException('The site analysis isn\'t ready yet. Try again in a minute or two.');
        }

        // کاربری که باید بابت این تحلیل کریدیت/توکن‌اش کسر بشه: اول مالک واقعی
        // سشن (session->user_id)، و در صورت نبود، مالک سایت.
        $billableUser = $session->user ?? $site->user;

        if (! $billableUser) {
            Log::error('CompetitorGap: no billable user resolved for session', [
                'session_id' => $session->id,
                'site_id' => $site->id,
            ]);
        }

        $keywords = $this->researchKeywords($site);

        Log::info('CompetitorGap: keywords resolved', [
            'session_id' => $session->id,
            'site_id' => $site->id,
            'count' => count($keywords),
            'keywords' => array_column($keywords, 'keyword'),
        ]);

        if ($keywords === []) {
            throw new RuntimeException('Competitor gap analysis needs at least one valid keyword.');
        }

        $this->rememberProgress(
            $session,
            'fetching_serp',
            'در حال بررسی نتایج گوگل',
            'برای هر کلمه کلیدی، رتبه سایت شما و رقبا از گوگل خوانده می‌شود.'
        );

        $serp = $this->gatherSerpData($site, $keywords);

        Log::info('CompetitorGap: serp gathering finished', [
            'session_id' => $session->id,
            'keywords_processed' => count($serp['per_keyword']),
            'distinct_competitor_domains' => count($serp['competitor_agg']),
            'competitor_domains' => array_keys($serp['competitor_agg']),
        ]);

        if ($serp['competitor_agg'] === []) {
            throw new RuntimeException('No real competitors were found in Google for these keywords.');
        }

        $competitors = $this->buildCompetitors($serp['competitor_agg'], count($serp['per_keyword']));
        $gapCandidates = $this->buildGapCandidates($serp['per_keyword'], $competitors);

        if ($gapCandidates === []) {
            throw new RuntimeException('No actionable competitor gaps were found for these keywords.');
        }

        $this->rememberProgress(
            $session,
            'analyzing',
            'در حال تحلیل و نگارش',
            'نتایج گوگل در حال تبدیل‌شدن به تحلیل و برنامه محتوایی هستند.'
        );

        $analysis = $this->analyzeWithAi($site, $competitors, $gapCandidates, $session, $billableUser);

        $this->storeResult($session, $site, $competitors, $gapCandidates, $analysis);
    }

    // ------------------------------------------------------------------
    // مرحله ۱: خواندن SERP واقعی از JustSerp
    // ------------------------------------------------------------------

    protected function gatherSerpData(Site $site, array $keywords): array
    {
        $serpClient = JustSerpApiClient::fromConfig();
        $siteDomain = $this->domain((string) $site->domain);
        $language = ContentLanguage::forSite($site);
        $serpOptions = ['language' => $language, 'country' => ContentLanguage::country($language)];

        Log::info('CompetitorGap: starting SERP gathering', [
            'site_id' => $site->id,
            'site_domain_raw' => $site->domain,
            'site_domain_normalized' => $siteDomain,
            'keywords_count' => count($keywords),
        ]);

        $perKeyword = [];
        $competitorAgg = [];

        foreach ($keywords as $item) {
            $keyword = trim((string) ($item['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }

            try {
                $results = $serpClient->search($keyword, $serpOptions);
            } catch (Throwable $exception) {
                Log::warning('JustSerp search failed for keyword', [
                    'keyword' => $keyword,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            Log::info('CompetitorGap: raw JustSerp results for keyword', [
                'keyword' => $keyword,
                'results_count' => count($results),
                'results' => array_map(fn ($r) => [
                    'position' => $r['position'] ?? null,
                    'link' => $r['link'] ?? null,
                    'domain' => $this->domain((string) ($r['link'] ?? '')),
                ], $results),
            ]);

            $siteEntry = null;
            $competitorEntries = [];

            foreach ($results as $result) {
                $domain = $this->domain((string) ($result['link'] ?? ''));
                if ($domain === '') {
                    continue;
                }

                if ($domain === $siteDomain) {
                    if ($siteEntry === null || $result['position'] < $siteEntry['position']) {
                        $siteEntry = $result;
                    }

                    continue;
                }

                $entry = $result + ['domain' => $domain];
                $competitorEntries[] = $entry;

                $agg = $competitorAgg[$domain] ?? ['domain' => $domain, 'positions' => [], 'samples' => []];
                $agg['positions'][] = $result['position'];
                if (count($agg['samples']) < self::MAX_SAMPLES_PER_COMPETITOR) {
                    $agg['samples'][] = [
                        'keyword' => $keyword,
                        'title' => $result['title'],
                        'url' => $result['link'],
                        'snippet' => $result['snippet'],
                        'position' => $result['position'],
                    ];
                }
                $competitorAgg[$domain] = $agg;
            }

            usort($competitorEntries, fn (array $a, array $b) => $a['position'] <=> $b['position']);

            Log::info('CompetitorGap: keyword processed', [
                'keyword' => $keyword,
                'site_found' => $siteEntry !== null,
                'site_position' => $siteEntry['position'] ?? null,
                'competitor_entries_count' => count($competitorEntries),
            ]);

            $perKeyword[] = [
                'keyword' => $keyword,
                'site_entry' => $siteEntry,
                'competitors' => array_slice($competitorEntries, 0, 5),
            ];
        }

        return ['per_keyword' => $perKeyword, 'competitor_agg' => $competitorAgg];
    }

    // ------------------------------------------------------------------
    // مرحله ۲: امتیازدهی قطعی به رقبا (بدون AI)
    // ------------------------------------------------------------------

    protected function buildCompetitors(array $competitorAgg, int $keywordCount): array
    {
        $keywordCount = max(1, $keywordCount);
        $competitors = [];

        foreach ($competitorAgg as $domain => $agg) {
            $positions = $agg['positions'];
            $count = count($positions);
            $avgPosition = array_sum($positions) / $count;

            $frequency = min(1, $count / $keywordCount);
            $positionQuality = max(0, min(1, (11 - $avgPosition) / 10));
            $score = $this->score((0.5 * $frequency + 0.5 * $positionQuality) * 100);

            $competitors[$domain] = [
                'domain' => $domain,
                'positions' => array_values($positions),
                'best_position' => min($positions),
                'appearances' => $count,
                'samples' => $agg['samples'],
                'visibility_score' => $score,
                'threat_score' => $score,
                'threat_level' => $this->threatLevelFromScore($score),
            ];
        }

        uasort($competitors, fn (array $a, array $b) => $b['threat_score'] <=> $a['threat_score']);

        return array_slice($competitors, 0, self::MAX_COMPETITORS, true);
    }

    // ------------------------------------------------------------------
    // مرحله ۳: تشخیص شکاف واقعی (بدون AI)
    // ------------------------------------------------------------------

    protected function buildGapCandidates(array $perKeyword, array $competitors): array
    {
        $candidates = [];

        foreach ($perKeyword as $entry) {
            $siteEntry = $entry['site_entry'];
            $bestCompetitor = $entry['competitors'][0] ?? null;

            $isMissing = $siteEntry === null;
            $isOutranked = $siteEntry !== null && $bestCompetitor !== null
                && $bestCompetitor['position'] < $siteEntry['position'];

            if (! $isMissing && ! $isOutranked) {
                continue;
            }

            $competitorDomain = $bestCompetitor['domain'] ?? null;
            $competitorInfo = $competitorDomain !== null ? ($competitors[$competitorDomain] ?? null) : null;

            $priority = $isMissing
                ? ($bestCompetitor !== null ? 60 + (11 - $bestCompetitor['position']) * 4 : 50)
                : 40 + ($siteEntry['position'] - $bestCompetitor['position']) * 6;

            $candidates[] = [
                'id' => count($candidates) + 1,
                'keyword' => $entry['keyword'],
                'site_position' => $siteEntry['position'] ?? null,
                'site_coverage' => $isMissing ? 'missing' : (($siteEntry['position'] ?? 99) <= 3 ? 'weak' : 'ranking_low'),
                'gap_type' => $isMissing ? 'topic' : 'position',
                'priority_score' => $this->score($priority),
                'threat_level' => $competitorInfo['threat_level'] ?? 'medium',
                'competitor_domain' => $competitorDomain,
                'competitor_url' => $bestCompetitor['link'] ?? null,
                'competitor_title' => $bestCompetitor['title'] ?? null,
                'competitor_snippet' => $bestCompetitor['snippet'] ?? null,
                'competitor_position' => $bestCompetitor['position'] ?? null,
            ];

            if (count($candidates) >= self::MAX_GAP_CANDIDATES) {
                break;
            }
        }

        usort($candidates, function (array $a, array $b) {
            if (($a['site_coverage'] === 'missing') xor ($b['site_coverage'] === 'missing')) {
                return $a['site_coverage'] === 'missing' ? -1 : 1;
            }

            return $b['priority_score'] <=> $a['priority_score'];
        });

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['id'] = $index + 1;
        }

        return $candidates;
    }

    // ------------------------------------------------------------------
    // مرحله ۴: فقط بخش نوشتاری با gpt-5-nano
    // ------------------------------------------------------------------

    protected function analyzeWithAi(
        Site $site,
        array $competitors,
        array $gapCandidates,
        ContentResearchSession $session,
        ?\App\Models\User $billableUser,
    ): array {
        $payload = [
            'model' => self::AI_MODEL,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'competitor_gap_analysis',
                    'strict' => true,
                    'schema' => $this->analysisSchema(),
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => ContentLanguage::localize($this->systemPrompt(), ContentLanguage::forSite($site))],
                ['role' => 'user', 'content' => $this->buildUserMessage($site, $competitors, $gapCandidates)],
            ],
        ];

        $response = $this->callGapgptChat($payload);

        if (! $response->successful()) {
            Log::error('CompetitorGap AI request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('AI competitor gap analysis failed.');
        }

        $message = $response->json('choices.0.message.content');
        $usage = $response->json('usage');

        // --- فیکس اصلی ---
        // قبلاً اینجا به app()->bound('current_ai_user') وابسته بود که چون
        // این سرویس داخل یک Job صف‌شده اجرا می‌شود (نه در چرخه‌ی یک HTTP
        // request)، آن binding هیچ‌وقت ست نمی‌شد و در نتیجه هیچ مصرف توکن/
        // کریدیتی برای این سرویس هیچ‌وقت در دیتابیس ثبت نمی‌شد، با اینکه
        // تماس واقعی با gpt-5-nano انجام و هزینه‌اش متحمل شده بود.
        // حالا مستقیماً از کاربر مالک سشن (که از قبل با $session->load در
        // run() بارگذاری شده) استفاده می‌کنیم.
        if ($usage && $billableUser) {
            $this->recordAiTokenUsage(
                user: $billableUser,
                serviceName: 'ai.competitor_gap_research',
                usage: $usage,
                model: self::AI_MODEL,
                metadata: ['content_research_session_id' => $session->id],
            );
        } elseif ($usage) {
            Log::error('CompetitorGap: AI usage returned but no billable user resolved; credit NOT deducted', [
                'session_id' => $session->id,
                'usage' => $usage,
            ]);
        } else {
            Log::warning('CompetitorGap: AI response had no usage field', [
                'session_id' => $session->id,
            ]);
        }

        if (! $message) {
            throw new RuntimeException('No response from the AI.');
        }

        $parsed = json_decode((string) $message, true);
        if (! is_array($parsed)) {
            Log::error('CompetitorGap AI response not parseable', ['raw_message' => $message]);

            throw new RuntimeException('The AI response couldn\'t be processed.');
        }

        return $parsed;
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
شما یک تحلیل‌گر ارشد سئو و استراتژی محتوا برای بازار فارسی هستید. فقط داده‌های واقعی نتایج جست‌وجوی گوگل (عنوان، لینک، snippet) در اختیار شماست؛ به صفحه‌ی کامل رقبا دسترسی ندارید. هرگز درباره‌ی چیزی که در همین داده‌ها نیامده حدس یا ادعا نکن؛ فقط با استنباط منطقی و محتاطانه از همان عنوان/snippet بنویس.

برای هر رقیب (بر اساس id) فقط این دو متن را تولید کن: summary که خلاصه کند چرا این دامنه در نتایج رقیب محسوب می‌شود و چه چیزی از عنوان/snippet آن برمی‌آید؛ و gap که فقط با تکیه بر شواهد موجود توضیح دهد سایت ما چه کمبودی نسبت به آن دارد. اگر شواهد کافی نیست، محتاطانه بنویس و ادعای بدون مدرک نکن.

برای هر شکاف کلمه‌کلیدی (بر اساس id) فقط این موارد را تولید کن: نوع نیت جست‌وجو، توضیح شکاف، زاویه‌ی پیشنهادی محتوا، عنوان پیشنهادی دقیق و قالب پیشنهادی (راهنمای جامع/مقایسه/صفحه محصول/سؤالات متداول و ...). از تولید outline، دلیل فرصت، رویکرد رقیب یا توضیح جداگانه‌ی site gap خودداری کن؛ این موارد در فرانت‌اند نمایش داده نمی‌شوند.

در پایان: یک summary تحلیلی کوتاه، یک site_analysis کلی شامل نقاط قوت/ضعف، وضعیت دیده‌شدن در جست‌وجو و عمق محتوا، و حداکثر ۷ توصیه. برای هر توصیه فقط title، description و action را تولید کن؛ priority در بک‌اند تعیین می‌شود.

فارسی روان، حرفه‌ای، مشخص و قابل‌اجرا بنویس؛ از کلی‌گویی، تکرار و ترجمه‌ی تحت‌اللفظی خودداری کن. فقط خروجی JSON مطابق Schema بده، بدون هیچ متن اضافه.
PROMPT;
    }

    protected function buildUserMessage(Site $site, array $competitors, array $gapCandidates): string
    {
        $entity = $site->entity;

        $competitorsPayload = [];
        $id = 1;
        foreach ($competitors as $domain => $competitor) {
            $competitorsPayload[] = [
                'id' => $id,
                'domain' => $domain,
                'samples' => $competitor['samples'],
            ];
            $id++;
        }

        $gapsPayload = array_map(fn (array $c) => [
            'id' => $c['id'],
            'keyword' => $c['keyword'],
            'site_coverage' => $c['site_coverage'],
            'competitor_domain' => $c['competitor_domain'],
            'competitor_title' => $c['competitor_title'],
            'competitor_snippet' => $c['competitor_snippet'],
        ], $gapCandidates);

        $data = [
            'site_domain' => $site->domain,
            'site_niche' => $site->niche,
            'primary_entity' => $entity?->primary_entity,
            'core_entities' => $entity?->core_entities,
            'competitors' => $competitorsPayload,
            'keyword_gaps' => $gapsPayload,
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function analysisSchema(): array
    {
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => ['type' => 'string'],
                'site_analysis' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'strengths' => $stringList,
                        'weaknesses' => $stringList,
                        'search_visibility' => ['type' => 'string'],
                        'content_depth' => ['type' => 'string'],
                    ],
                    'required' => ['strengths', 'weaknesses', 'search_visibility', 'content_depth'],
                ],
                'competitors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'gap' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'name', 'summary', 'gap'],
                    ],
                ],
                'keyword_gaps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'intent' => ['type' => 'string', 'enum' => ['informational', 'commercial', 'transactional', 'navigational']],
                            'description' => ['type' => 'string'],
                            'recommended_angle' => ['type' => 'string'],
                            'recommended_title' => ['type' => 'string'],
                            'recommended_format' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'intent', 'description', 'recommended_angle', 'recommended_title', 'recommended_format'],
                    ],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'description', 'action'],
                    ],
                ],
            ],
            'required' => ['summary', 'site_analysis', 'competitors', 'keyword_gaps', 'recommendations'],
        ];
    }

    // ------------------------------------------------------------------
    // ذخیره‌سازی: ادغام داده‌ی قطعی (PHP) + نوشته‌ی AI بر اساس id
    // ------------------------------------------------------------------

    protected function storeResult(
        ContentResearchSession $session,
        Site $site,
        array $competitors,
        array $gapCandidates,
        array $analysis,
    ): void {
        $competitorNarrative = collect($analysis['competitors'] ?? [])->keyBy('id');
        $gapNarrative = collect($analysis['keyword_gaps'] ?? [])->keyBy('id');

        DB::transaction(function () use ($session, $site, $competitors, $gapCandidates, $analysis, $competitorNarrative, $gapNarrative): void {
            DB::table('site_competitors')->where('content_research_session_id', $session->id)->delete();
            DB::table('content_gaps')->where('content_research_session_id', $session->id)->delete();

            $competitorIds = [];
            $id = 1;
            foreach ($competitors as $domain => $competitor) {
                $narrative = $competitorNarrative->get($id, []);
                $currentId = $id;
                $id++;

                $evidenceUrls = array_values(array_unique(array_column($competitor['samples'], 'url')));

                $rowId = DB::table('site_competitors')->insertGetId([
                    'content_research_session_id' => $session->id,
                    'site_id' => $site->id,
                    'domain' => $domain,
                    'display_name' => trim((string) ($narrative['name'] ?? '')) ?: $domain,
                    'best_position' => $competitor['best_position'],
                    'visibility_score' => $competitor['visibility_score'],
                    'threat_level' => $competitor['threat_level'],
                    'threat_score' => $competitor['threat_score'],
                    'data_source' => self::DATA_SOURCE,
                    'evidence' => $this->json([
                        'source' => self::DATA_SOURCE,
                        'summary' => trim((string) ($narrative['summary'] ?? '')),
                        'gap' => trim((string) ($narrative['gap'] ?? '')),
                        'keywords' => array_values(array_unique(array_column($competitor['samples'], 'keyword'))),
                        'evidence_urls' => $evidenceUrls,
                        'ranking_positions' => $competitor['positions'],
                        'appearances' => $competitor['appearances'],
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $competitorIds[$domain] = $rowId;
                unset($currentId);
            }

            foreach ($gapCandidates as $candidate) {
                $narrative = $gapNarrative->get($candidate['id'], []);

                $title = trim((string) ($narrative['recommended_title'] ?? '')) ?: 'فرصت محتوایی برای '.$candidate['keyword'];
                $evidenceUrls = array_values(array_filter([$candidate['competitor_url']]));

                DB::table('content_gaps')->insert([
                    'content_research_session_id' => $session->id,
                    'site_id' => $site->id,
                    'site_competitor_id' => $candidate['competitor_domain']
                        ? ($competitorIds[$candidate['competitor_domain']] ?? null)
                        : null,
                    'query' => $candidate['keyword'],
                    'title' => $title,
                    'gap_type' => $this->gapType($candidate['gap_type']),
                    'description' => trim((string) ($narrative['description'] ?? ''))
                        ?: 'برای این keyword در مقایسه با نتایج رقیب فرصت محتوایی شناسایی شد.',
                    'recommended_angle' => trim((string) ($narrative['recommended_angle'] ?? ''))
                        ?: 'محتوایی کامل‌تر، دقیق‌تر و منطبق با نیت جست‌وجوی کاربر تولید کنید.',
                    'opportunity_score' => $candidate['priority_score'],
                    'threat_level' => $candidate['threat_level'],
                    'data_source' => self::DATA_SOURCE,
                    'evidence' => $this->json([
                        'source' => self::DATA_SOURCE,
                        'keyword' => $candidate['keyword'],
                        'intent' => (string) ($narrative['intent'] ?? 'informational'),
                        'site_coverage' => $candidate['site_coverage'],
                        'current_site_position' => $candidate['site_position'],
                        'competitor_domain' => $candidate['competitor_domain'],
                        'competitor_url' => $candidate['competitor_url'],
                        'competitor_title' => $candidate['competitor_title'],
                        'competitor_position' => $candidate['competitor_position'],
                        'recommended_format' => (string) ($narrative['recommended_format'] ?? ''),
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $metadata = is_array($session->metadata) ? $session->metadata : [];
            $metadata['research'] = [
                'data_source' => self::DATA_SOURCE,
                'summary' => trim((string) ($analysis['summary'] ?? '')),
                'site_analysis' => [
                    'status' => 'reviewed',
                    'strengths' => $this->strings($analysis['site_analysis']['strengths'] ?? []),
                    'weaknesses' => $this->strings($analysis['site_analysis']['weaknesses'] ?? []),
                    'search_visibility' => (string) ($analysis['site_analysis']['search_visibility'] ?? ''),
                    'content_depth' => (string) ($analysis['site_analysis']['content_depth'] ?? ''),
                ],
                'recommendations' => $this->normalizeRecommendations(
                    is_array($analysis['recommendations'] ?? null) ? $analysis['recommendations'] : []
                ),
                'progress' => [
                    'status' => 'done',
                    'stage' => 'completed',
                    'title' => 'تحلیل تکمیل شد',
                    'message' => 'Your site, competitor and actionable gap analysis is ready.',
                    'updated_at' => now()->toISOString(),
                ],
                'completed_at' => now()->toISOString(),
            ];

            $session->update([
                'status' => 'done',
                'data_source' => self::DATA_SOURCE,
                'metadata' => $metadata,
                'error' => null,
                'completed_at' => now(),
            ]);
        });
    }

    protected function normalizeRecommendations(array $recommendations): array
    {
        $normalized = [];
        foreach ($recommendations as $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }

            $action = trim((string) ($recommendation['action'] ?? ''));
            if ($action === '') {
                continue;
            }

            $normalized[] = [
                'priority' => count($normalized) + 1,
                'title' => trim((string) ($recommendation['title'] ?? 'اقدام پیشنهادی')),
                'description' => trim((string) ($recommendation['description'] ?? '')),
                'action' => $action,
            ];
        }

        return array_slice($normalized, 0, 7);
    }

    // ------------------------------------------------------------------
    // کمکی‌های مشترک
    // ------------------------------------------------------------------

 protected function researchKeywords(Site $site): array
{
    $result = collect();

    // ۱. GSC — منبع اصلی و معتبرترین (رتبه‌بندی واقعی گوگل)
    $gscKeywords = DB::table('search_console_query_metrics')
        ->where('site_id', $site->id)
        ->orderByDesc('impressions')
        ->orderByDesc('clicks')
        ->limit(self::MAX_KEYWORDS)
        ->get()
        ->map(fn (object $metric) => ['keyword' => trim((string) $metric->query)])
        ->filter(fn (array $item) => $item['keyword'] !== '');

    $result = $result->concat($gscKeywords);

    // ۲. اگر به سقف نرسیدیم، از EntityKeyword پر کن
    if ($result->count() < self::MAX_KEYWORDS) {
        $remaining = self::MAX_KEYWORDS - $result->count();

        $entityKeywords = EntityKeyword::query()
            ->where('site_id', $site->id)
            ->orderByDesc('total_relevance')
            ->limit(self::MAX_KEYWORDS) // بیشتر می‌گیریم تا بعد از dedupe جا کم نیاریم
            ->get(['base_keyword'])
            ->map(fn (EntityKeyword $keyword) => ['keyword' => trim((string) $keyword->base_keyword)])
            ->filter(fn (array $item) => $item['keyword'] !== '');

        $result = $result->concat($entityKeywords);
    }

    // ۳. اگر هنوز به سقف نرسیدیم، از site->keywords هم پر کن
    if ($result->count() < self::MAX_KEYWORDS) {
        $result = $result->concat($this->keywordsFromSite($site));
    }

    return $result
        ->unique('keyword')
        ->take(self::MAX_KEYWORDS)
        ->values()
        ->all();
}

    /**
     * آخرین fallback: وقتی نه GSC و نه EntityKeyword چیزی داشتن، از خود
     * site->keywords استفاده می‌کنیم — که یک آرایه‌ی ساده از رشته‌هاست، مثل:
     * ["لوازم آرایشگاهی", "فروشگاه اینترنتی", "آبرسان", ...]
     */
    protected function keywordsFromSite(Site $site): array
    {
        $keywords = $site->keywords;

        if (is_string($keywords)) {
            $decoded = json_decode($keywords, true);
            $keywords = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($keywords)) {
            return [];
        }

        return collect($keywords)
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => ['keyword' => trim($item)])
            ->unique('keyword')
            ->take(self::MAX_KEYWORDS)
            ->values()
            ->all();
    }

    protected function rememberProgress(ContentResearchSession $session, string $stage, string $title, string $message): void
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $metadata['research'] = array_merge($metadata['research'] ?? [], [
            'progress' => [
                'status' => 'processing',
                'stage' => $stage,
                'title' => $title,
                'message' => $message,
                'updated_at' => now()->toISOString(),
            ],
        ]);

        $session->update(['metadata' => $metadata]);
    }

    protected function threatLevelFromScore(int $score): string
    {
        return $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');
    }

    protected function domain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $host = parse_url(str_starts_with($value, 'http') ? $value : 'https://'.$value, PHP_URL_HOST);
        $host = is_string($host) ? mb_strtolower($host) : '';

        return preg_replace('/^www\./', '', $host) ?: '';
    }

    protected function score($value): int
    {
        return max(0, min(100, (int) round((float) $value)));
    }

    protected function gapType($value): string
    {
        return in_array($value, ['topic', 'position', 'format', 'intent', 'freshness', 'trust'], true)
            ? $value
            : 'topic';
    }

    protected function strings($value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => trim($item))
            ->unique()
            ->values()
            ->all();
    }

    protected function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
