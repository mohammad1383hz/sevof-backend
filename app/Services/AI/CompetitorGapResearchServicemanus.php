<?php

namespace App\Services\AI;

use App\Models\ContentResearchSession;
use App\Models\EntityKeyword;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Manus-backed competitor and keyword-gap research.
 *
 * This service intentionally owns only the competitor_gaps flow. Keyword
 * clustering remains in ContentResearchService.
 */
class CompetitorGapResearchService
{
    public const TYPE_COMPETITOR_GAPS = 'competitor_gaps';

    private const DATA_SOURCE = 'manus';
    private const POLL_INTERVAL_SECONDS = 5;
    private const MAX_POLL_SECONDS = 780;
    private const MAX_NOT_FOUND_SECONDS = 180;
    private const MAX_KEYWORDS = 15;
    private const MAX_LOG_BODY_LENGTH = 20000;

    public function run(ContentResearchSession $session): void
    {
        $session->load(['site.entity', 'site.user']);
        $site = $session->site;

        if (! $site || ! $site->entity) {
            throw new RuntimeException('The site\'s entity analysis must finish before competitor gap research.');
        }

        $keywords = $this->researchKeywords($site);
        if ($keywords === []) {
            throw new RuntimeException('Competitor gap analysis needs at least one valid keyword.');
        }

        $created = $this->createTask($site, $session, $keywords);
        $taskId = $created['task_id'];
        $taskUrl = $created['task_url'];

        Log::info('Manus competitor gap task created', [
            'session_id' => $session->id,
            'task_id' => $taskId,
            'task_url' => $taskUrl,
        ]);

        $this->rememberTask($session, $taskId, $taskUrl);
        $result = $this->waitForResult($session, $taskId);
        $this->storeResult($session, $site, $result, $taskId, $taskUrl);
    }

    protected function createTask(Site $site, ContentResearchSession $session, array $keywords): array
    {
        $payload = [
            'message' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $this->buildPrompt($site, $session->prompt, $keywords),
                    ],
                ],
            ],
            'interactive_mode' => false,
            'hide_in_task_list' => true,
            'share_visibility' => 'private',
            'agent_profile' => config('services.manus.content_gap_agent_profile', 'manus-1.6-max'),
            'title' => 'تحلیل شکاف رقبا و کلمات کلیدی',
            'structured_output_schema' => $this->resultSchema(),
        ];

        $response = $this->manusRequest('task.create', 'POST', '/v2/task.create', $payload);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException($this->apiError($response, 'Creating the Manus competitor gap task failed.'));
        }

        $taskId = trim((string) $response->json('task_id'));
        if ($taskId === '') {
            throw new RuntimeException('Manus did not return a valid task_id for the gap research task.');
        }

        // فرصت کوتاه برای visibility اولیه تسک قبل از شروع poll
        sleep(2);

        return [
            'task_id' => $taskId,
            'task_url' => (string) $response->json('task_url'),
        ];
    }

    protected function waitForResult(ContentResearchSession $session, string $taskId): array
    {
        $startedAt = microtime(true);
        $autoContinued = false;
        $messagesNotFoundSince = null;

        while ((microtime(true) - $startedAt) < self::MAX_POLL_SECONDS) {
            // listMessages هم وضعیت واقعی و هم پیام‌های مرحله‌ای و نتیجه را برمی‌گرداند.
            $messagesResponse = $this->taskMessages($taskId);
            if (! $messagesResponse->successful() || $messagesResponse->json('ok') !== true) {
                if ($messagesResponse->status() === 404 && $messagesResponse->json('error.code') === 'not_found') {
                    $messagesNotFoundSince ??= microtime(true);
                    $elapsedNotFound = microtime(true) - $messagesNotFoundSince;

                    if ($elapsedNotFound < self::MAX_NOT_FOUND_SECONDS) {
                        Log::warning('Manus task messages are not visible yet; retrying task.listMessages', [
                            'task_id' => $taskId,
                            'elapsed_not_found_seconds' => round($elapsedNotFound),
                        ]);
                        sleep(self::POLL_INTERVAL_SECONDS);
                        continue;
                    }
                }

                throw new RuntimeException($this->apiError($messagesResponse, 'Fetching Manus task messages failed for task_id='.$taskId));
            }

            $messagesNotFoundSince = null;
            $messages = is_array($messagesResponse->json('messages')) ? $messagesResponse->json('messages') : [];
            $status = $this->latestStatus($messages) ?: 'running';
            $this->rememberProgress($session, $this->progressFromMessages($messages, $status));

            if ($status === 'error') {
                throw new RuntimeException($this->taskError($messages));
            }

            if ($status === 'waiting') {
                if (! $autoContinued && $this->continueAfterQuestion($taskId, $messages)) {
                    $autoContinued = true;
                    sleep(self::POLL_INTERVAL_SECONDS);
                    continue;
                }

                throw new RuntimeException($this->waitingError($messages));
            }

            // در برخی پاسخ‌ها نتیجه JSON داخل assistant_message.content می‌آید، نه structured_output_result.
            $structured = $this->structuredResult($messages);
            if ($structured !== null) {
                if (($structured['success'] ?? false) !== true) {
                    throw new RuntimeException((string) ($structured['error'] ?? 'Manus did not return valid structured output for the gap analysis.'));
                }

                $value = $structured['value'] ?? null;
                if (! is_array($value)) {
                    throw new RuntimeException('The Manus gap analysis output structure is invalid.');
                }

                Log::info('Manus content gap result accepted', [
                    'task_id' => $taskId,
                    'status' => $status,
                    'competitor_count' => count($this->competitors($value)),
                    'keyword_gap_count' => count($this->keywordGaps($value)),
                ]);

                return $value;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        throw new RuntimeException('Timed out waiting for the Manus gap analysis.');
    }

    protected function manusRequest(string $operation, string $method, string $path, array $data = [])
    {
        $url = $this->baseUrl().$path;
        $startedAt = microtime(true);

        Log::info('Manus request started', [
            'operation' => $operation,
            'method' => strtoupper($method),
            'url' => $url,
            'data' => $data,
        ]);

        try {
            $request = Http::withHeaders([
                'x-manus-api-key' => $this->apiKey(),
            ])
                ->acceptJson()
                ->connectTimeout(15)
                ->timeout(60);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                default => throw new RuntimeException('Unsupported HTTP method for Manus request: '.$method),
            };
        } catch (Throwable $exception) {
            Log::error('Manus request failed', [
                'operation' => $operation,
                'method' => strtoupper($method),
                'url' => $url,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'exception' => get_class($exception),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::info('Manus response received', [
            'operation' => $operation,
            'method' => strtoupper($method),
            'url' => $url,
            'status' => $response->status(),
            'ok' => $response->json('ok'),
            'request_id' => $response->json('request_id'),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'body' => mb_substr($response->body(), 0, self::MAX_LOG_BODY_LENGTH),
        ]);

        return $response;
    }

    protected function taskDetail(string $taskId)
    {
        return $this->manusRequest('task.detail', 'GET', '/v2/task.detail', [
            'task_id' => $taskId,
        ]);
    }

    protected function taskListDiagnostic(string $taskId): ?bool
    {
        $response = $this->manusRequest('task.list.diagnostic', 'GET', '/v2/task.list', [
            'order' => 'desc',
            'limit' => 100,
        ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('Unable to diagnose Manus task visibility with task.list', [
                'task_id' => $taskId,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        return collect($response->json('data', []))
            ->contains(fn ($task) => (string) ($task['id'] ?? '') === $taskId);
    }

    protected function taskMessages(string $taskId)
    {
        return $this->manusRequest('task.listMessages', 'GET', '/v2/task.listMessages', [
            'task_id' => $taskId,
            'order' => 'desc',
            'limit' => 100,
        ]);
    }

    protected function storeResult(
        ContentResearchSession $session,
        Site $site,
        array $result,
        string $taskId,
        string $taskUrl,
    ): void {
        DB::transaction(function () use ($session, $site, $result, $taskId, $taskUrl): void {
            DB::table('site_competitors')
                ->where('content_research_session_id', $session->id)
                ->delete();
            DB::table('content_gaps')
                ->where('content_research_session_id', $session->id)
                ->delete();

            $competitorIds = [];
            foreach ($this->competitors($result) as $competitor) {
                $domain = $this->domain((string) ($competitor['domain'] ?? ''));
                if ($domain === '') {
                    continue;
                }

                $positions = collect($competitor['ranking_positions'] ?? [])
                    ->filter(fn ($position) => is_numeric($position))
                    ->map(fn ($position) => (int) $position)
                    ->filter(fn (int $position) => $position > 0)
                    ->values();
                $bestPosition = $positions->isNotEmpty() ? $positions->min() : $this->nullableInt($competitor['best_position'] ?? null);
                $threatScore = $this->score($competitor['threat_score'] ?? 0);

                $id = DB::table('site_competitors')->insertGetId([
                    'content_research_session_id' => $session->id,
                    'site_id' => $site->id,
                    'domain' => $domain,
                    'display_name' => trim((string) ($competitor['name'] ?? $domain)) ?: $domain,
                    'best_position' => $bestPosition,
                    'visibility_score' => $this->score($competitor['visibility_score'] ?? $threatScore),
                    'threat_level' => $this->threatLevel($competitor['threat_level'] ?? null, $threatScore),
                    'threat_score' => $threatScore,
                    'data_source' => self::DATA_SOURCE,
                    'evidence' => $this->json([
                        'source' => self::DATA_SOURCE,
                        'why_competes' => (string) ($competitor['reason'] ?? ''),
                        'competitor_page_title' => (string) ($competitor['competitor_page_title'] ?? ''),
                        'competitor_page_url' => (string) ($competitor['competitor_page_url'] ?? ''),
                        'what_they_cover' => (string) ($competitor['what_they_cover'] ?? ''),
                        'content_strengths' => $this->strings($competitor['content_strengths'] ?? []),
                        'missing_on_site' => $this->strings($competitor['missing_on_site'] ?? []),
                        'keywords' => $this->strings($competitor['keywords'] ?? []),
                        'evidence_urls' => $this->strings($competitor['evidence_urls'] ?? []),
                        'ranking_positions' => $positions->all(),
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $competitorIds[$domain] = $id;
            }

            foreach ($this->keywordGaps($result) as $gap) {
                $keyword = trim((string) ($gap['keyword'] ?? ''));
                if ($keyword === '') {
                    continue;
                }

                $competitorDomain = $this->domain((string) ($gap['competitor_domain'] ?? ''));
                $title = trim((string) ($gap['recommended_title'] ?? ''));
                if ($title === '') {
                    $title = trim((string) ($gap['competitor_title'] ?? '')) ?: 'فرصت محتوایی برای '.$keyword;
                }

                DB::table('content_gaps')->insert([
                    'content_research_session_id' => $session->id,
                    'site_id' => $site->id,
                    'site_competitor_id' => $competitorIds[$competitorDomain] ?? null,
                    'query' => $keyword,
                    'title' => $title,
                    'gap_type' => $this->gapType($gap['gap_type'] ?? null),
                    'description' => trim((string) ($gap['description'] ?? '')) ?: 'برای این keyword در مقایسه با نتایج رقیب فرصت محتوایی شناسایی شد.',
                    'recommended_angle' => trim((string) ($gap['recommended_angle'] ?? '')) ?: 'محتوایی کامل‌تر، دقیق‌تر و منطبق با نیت جست‌وجوی کاربر تولید کنید.',
                    'opportunity_score' => $this->score($gap['priority_score'] ?? 0),
                    'threat_level' => $this->threatLevel($gap['threat_level'] ?? null, $this->score($gap['priority_score'] ?? 0)),
                    'data_source' => self::DATA_SOURCE,
                    'evidence' => $this->json([
                        'source' => self::DATA_SOURCE,
                        'keyword' => $keyword,
                        'intent' => (string) ($gap['intent'] ?? ''),
                        'site_coverage' => (string) ($gap['site_coverage'] ?? ''),
                        'current_site_position' => $gap['current_site_position'] ?? null,
                        'competitor_domain' => $competitorDomain,
                        'competitor_url' => (string) ($gap['competitor_url'] ?? ''),
                        'competitor_title' => (string) ($gap['competitor_title'] ?? ''),
                        'competitor_position' => $gap['competitor_position'] ?? null,
                        'why_opportunity' => (string) ($gap['why_opportunity'] ?? ''),
                        'competitor_approach' => (string) ($gap['competitor_approach'] ?? ''),
                        'site_gap' => (string) ($gap['site_gap'] ?? ''),
                        'content_outline' => $this->strings($gap['content_outline'] ?? []),
                        'recommended_format' => (string) ($gap['recommended_format'] ?? ''),
                        'evidence_urls' => $this->strings($gap['evidence_urls'] ?? []),
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $metadata = is_array($session->metadata) ? $session->metadata : [];
            $metadata['manus'] = [
                'task_id' => $taskId,
                'task_url' => $taskUrl ?: null,
                'summary' => (string) ($result['summary'] ?? ''),
                'site_analysis' => is_array($result['site_analysis'] ?? null) ? $result['site_analysis'] : [],
                'recommendations' => $this->normalizeRecommendations(is_array($result['recommendations'] ?? null) ? $result['recommendations'] : []),
                'progress' => [
                    'status' => 'stopped',
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

    protected function buildPrompt(Site $site, ?string $userPrompt, array $keywords): string
    {
        $entity = $site->entity;
        $siteUrl = str_starts_with((string) $site->domain, 'http')
            ? (string) $site->domain
            : 'https://'.$site->domain;

        return implode("\n", [
            'نقش تو: تحلیل‌گر ارشد SEO، استراتژی محتوا و تحلیل رقابتی برای بازار فارسی.',
            'هدف: برای سایت ما شکاف‌های واقعی، مهم و قابل اقدام پیدا کن؛ راهنمای عمومی یا توصیه کلی تولید نکن.',
            'بدون سؤال تا پایان کار ادامه بده و فقط مواردی را ثبت کن که با بررسی واقعی صفحه سایت ما و صفحه رقیب پشتیبانی می‌شوند.',
            '۱) سایت ما را عمیق بررسی کن: صفحه اصلی، صفحات پلن/قیمت، شروع رایگان، قابلیت‌ها، اتصال‌ها و همه صفحات مرتبطی که پیدا می‌کنی. برای هر ادعا URL صفحه خودمان را ثبت کن.',
            '۲) برای هر keyword جست‌وجوی واقعی انجام بده. فقط رقبایی را انتخاب کن که صفحه مشخص آن‌ها برای همان نیت جست‌وجو قابل مشاهده و مرتبط است؛ صفحه اصلی دامنه یا شهرت برند به‌تنهایی مدرک نیست.',
            '۳) هر صفحه رقیب را از نظر هدف جست‌وجو، عمق محتوا، ساختار تیترها، پاسخ به سؤال کاربر، شفافیت قیمت/ویژگی، مثال، جدول، ابزار، اعتمادسازی، CTA و لینک‌سازی داخلی تحلیل کن.',
            '۴) شکاف فقط زمانی معتبر است که هم «رقیب چه چیزی را بهتر/کامل‌تر پوشش داده» و هم «سایت ما دقیقاً چه چیزی کم دارد» با URL و توضیح مشخص ثبت شود. اگر چنین مقایسه‌ای ممکن نیست، شکاف را حذف کن.',
            '۵) شکاف‌های سطحی مثل «مقاله بیشتری بنویس» قابل قبول نیست. برای هر شکاف، صفحه پیشنهادی، عنوان دقیق، قالب، ساختار بخش‌ها، پیام اصلی، CTA و معیار موفقیت ارائه بده.',
            '۶) بین نبود موضوع، پوشش ضعیف، رتبه پایین، ناهماهنگی نیت، تازگی، اعتماد و فرمت تمایز بگذار. سطح تهدید را بر اساس اثر واقعی همان صفحه بر تصمیم کاربر تعیین کن، نه حدس.',
            '۷) فارسی روان، حرفه‌ای و طبیعی بنویس. از ترجمه تحت‌اللفظی، عبارت‌های مبهم، تکرار، کلی‌گویی و لحن ماشینی خودداری کن. متن باید مستقیماً برای صاحب سایت قابل اجرا باشد.',
            '۸) فقط JSON مطابق Schema برگردان؛ خارج از JSON هیچ متن دیگری ننویس. همه URLها باید کامل و معتبر باشند. summary حداکثر ۴ جمله روشن، recommendations حداکثر ۷ اقدام اولویت‌دار.',
            'SITE_URL: '.$siteUrl,
            'SITE_NICHE: '.(string) $site->niche,
            'PRIMARY_ENTITY: '.json_encode($entity?->primary_entity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CORE_ENTITIES: '.json_encode($entity?->core_entities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'KEYWORDS_AND_SIGNALS: '.json_encode($keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'USER_REQUEST: '.(trim((string) $userPrompt) ?: 'رقبای واقعی و شکاف‌های محتوایی مهم سایت را با شواهد و برنامه اقدام تحلیل کن.'),
        ]);
    }

    protected function researchKeywords(Site $site): array
    {
        $metrics = DB::table('search_console_query_metrics')
            ->where('site_id', $site->id)
            ->orderByDesc('impressions')
            ->orderByDesc('clicks')
            ->limit(self::MAX_KEYWORDS)
            ->get();

        if ($metrics->isNotEmpty()) {
            return $metrics->map(fn (object $metric) => [
                'keyword' => (string) $metric->query,
                'clicks' => (int) ($metric->clicks ?? 0),
                'impressions' => (int) ($metric->impressions ?? 0),
                'position' => $metric->position !== null ? (float) $metric->position : null,
                'ctr' => (float) ($metric->ctr ?? 0),
            ])->filter(fn (array $item) => trim($item['keyword']) !== '')->values()->all();
        }

        return EntityKeyword::query()
            ->where('site_id', $site->id)
            ->orderByDesc('total_relevance')
            ->limit(self::MAX_KEYWORDS)
            ->get(['base_keyword', 'total_relevance'])
            ->map(fn (EntityKeyword $keyword) => [
                'keyword' => trim((string) $keyword->base_keyword),
                'relevance' => (int) ($keyword->total_relevance ?? 0),
                'position' => null,
            ])
            ->filter(fn (array $item) => $item['keyword'] !== '')
            ->unique('keyword')
            ->values()
            ->all();
    }

    protected function rememberTask(ContentResearchSession $session, string $taskId, string $taskUrl): void
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $metadata['manus'] = array_merge($metadata['manus'] ?? [], [
            'task_id' => $taskId,
            'task_url' => $taskUrl ?: null,
            'started_at' => now()->toISOString(),
            'progress' => [
                'status' => 'queued',
                'stage' => 'starting',
                'title' => 'درخواست ثبت شد',
                'message' => 'Checking your site and real competitors takes a few minutes; results will appear on this page.',
                'updated_at' => now()->toISOString(),
            ],
        ]);

        $session->update([
            'status' => 'processing',
            'data_source' => self::DATA_SOURCE,
            'metadata' => $metadata,
            'error' => null,
        ]);
    }

    protected function rememberProgress(ContentResearchSession $session, array $progress): void
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $metadata['manus'] = array_merge($metadata['manus'] ?? [], [
            'progress' => $progress,
        ]);

        $session->update(['metadata' => $metadata]);
    }

    protected function latestStatus(array $messages): ?string
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? null) === 'status_update') {
                $status = $message['status_update']['agent_status'] ?? null;
                if (is_string($status)) {
                    return $status;
                }
            }
        }

        return null;
    }

    protected function progressFromMessages(array $messages, string $status): array
    {
        $brief = '';
        $description = '';

        foreach ($messages as $message) {
            if (($message['type'] ?? null) !== 'status_update') {
                continue;
            }

            $update = is_array($message['status_update'] ?? null) ? $message['status_update'] : [];
            $brief = trim((string) ($update['brief'] ?? ''));
            $description = trim((string) ($update['description'] ?? ''));
            break;
        }

        $stage = match ($status) {
            'waiting' => 'waiting',
            'stopped' => 'finalizing',
            'error' => 'error',
            default => 'researching',
        };
        $labels = [
            'waiting' => ['title' => 'Awaiting reply', 'message' => 'We need your answer to continue.'],
            'finalizing' => ['title' => 'Preparing results', 'message' => 'The check is done; the detailed result is being prepared.'],
            'error' => ['title' => 'Check failed', 'message' => 'Something went wrong while running the check.'],
            'researching' => ['title' => 'Checking your site and competitors', 'message' => 'Comparing your site, search results and real competitor pages.'],
        ];

        return [
            'status' => $status,
            'stage' => $stage,
            'title' => $labels[$stage]['title'],
            'message' => $labels[$stage]['message'],
            'manus_brief' => $brief ?: null,
            'manus_description' => $description ?: null,
            'updated_at' => now()->toISOString(),
        ];
    }

    protected function continueAfterQuestion(string $taskId, array $messages): bool
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? null) !== 'status_update') {
                continue;
            }

            $detail = $message['status_update']['status_detail'] ?? [];
            if (($detail['waiting_for_event_type'] ?? null) !== 'messageAskUser') {
                return false;
            }

            $response = $this->manusRequest('task.sendMessage', 'POST', '/v2/task.sendMessage', [
                'task_id' => $taskId,
                'message' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'اطلاعات بیشتری لازم نیست. با فرضیات محافظه‌کارانه ادامه بده، فقط مواردی را ثبت کن که از بررسی واقعی سایت و صفحات رقیب شواهد دارند و در پایان خروجی ساخت‌یافته را کامل کن.',
                        ],
                    ],
                ],
            ]);

            if (! $response->successful() || $response->json('ok') !== true) {
                throw new RuntimeException($this->apiError($response, 'Continuing the Manus task failed.'));
            }

            return true;
        }

        return false;
    }

    protected function structuredResult(array $messages): ?array
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? null) !== 'structured_output_result') {
                continue;
            }

            $result = $message['structured_output_result'] ?? null;
            if (! is_array($result)) {
                continue;
            }

            if (array_key_exists('success', $result)) {
                if (($result['success'] ?? false) !== true) {
                    return $result;
                }

                $value = $result['value'] ?? null;
                return is_array($value)
                    ? ['success' => true, 'value' => $this->normalizeResult($value)]
                    : $result;
            }

            return ['success' => true, 'value' => $this->normalizeResult($result)];
        }

        // بعضی Taskها JSON را در assistant_message.content برمی‌گردانند.
        foreach ($messages as $message) {
            if (($message['type'] ?? null) !== 'assistant_message') {
                continue;
            }

            $content = $message['assistant_message']['content'] ?? null;
            if (is_array($content)) {
                $content = collect($content)
                    ->map(fn ($part) => is_array($part) ? ($part['text'] ?? $part['content'] ?? '') : $part)
                    ->filter(fn ($part) => is_string($part))
                    ->implode("\n");
            }

            if (! is_string($content) || trim($content) === '') {
                continue;
            }

            $decoded = json_decode(trim($content), true);
            if (is_array($decoded)) {
                return ['success' => true, 'value' => $this->normalizeResult($decoded)];
            }
        }

        return null;
    }

    protected function normalizeResult(array $result): array
    {
        $competitors = [];
        foreach (is_array($result['competitors'] ?? null) ? $result['competitors'] : [] as $competitor) {
            if (! is_array($competitor)) {
                continue;
            }

            $pageUrl = trim((string) ($competitor['competitor_page_url'] ?? $competitor['url'] ?? ''));
            $threatLevel = $this->normalizedThreatLevel($competitor['threat_level'] ?? null);
            $threatScore = $this->score($competitor['threat_score'] ?? $this->scoreForThreat($threatLevel));
            $evidenceUrls = array_merge(
                $this->strings($competitor['evidence_urls'] ?? []),
                $this->evidenceUrls($competitor['evidence'] ?? []),
            );
            if ($pageUrl !== '') {
                $evidenceUrls[] = $pageUrl;
            }

            $competitors[] = [
                'domain' => $this->domain((string) ($competitor['domain'] ?? $pageUrl)),
                'name' => trim((string) ($competitor['name'] ?? $competitor['display_name'] ?? '')),
                'reason' => trim((string) ($competitor['reason'] ?? $competitor['evidence'] ?? '')),
                'keywords' => $this->strings($competitor['keywords'] ?? $competitor['matched_keywords'] ?? []),
                'competitor_page_title' => trim((string) ($competitor['competitor_page_title'] ?? $competitor['title'] ?? '')),
                'competitor_page_url' => $pageUrl,
                'what_they_cover' => trim((string) ($competitor['what_they_cover'] ?? $competitor['evidence'] ?? '')),
                'content_strengths' => $this->strings($competitor['content_strengths'] ?? []),
                'missing_on_site' => $this->strings($competitor['missing_on_site'] ?? []),
                'evidence_urls' => array_values(array_unique($evidenceUrls)),
                'ranking_positions' => $this->positiveIntegers($competitor['ranking_positions'] ?? []),
                'best_position' => $this->nullableInt($competitor['best_position'] ?? null),
                'visibility_score' => $this->score($competitor['visibility_score'] ?? $threatScore),
                'threat_level' => $threatLevel,
                'threat_score' => $threatScore,
            ];
        }

        $gaps = [];
        foreach (is_array($result['keyword_gaps'] ?? null) ? $result['keyword_gaps'] : [] as $gap) {
            if (! is_array($gap)) {
                continue;
            }

            $keyword = trim((string) ($gap['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }

            $competitorUrl = trim((string) ($gap['competitor_page_url'] ?? $gap['competitor_url'] ?? ''));
            $competitorDomain = $this->domain((string) ($gap['competitor_domain'] ?? $competitorUrl));
            $threatLevel = $this->normalizedThreatLevel($gap['threat_level'] ?? null);
            $evidenceUrls = array_merge(
                $this->strings($gap['evidence_urls'] ?? []),
                $this->evidenceUrls($gap['evidence'] ?? []),
            );
            if ($competitorUrl !== '') {
                $evidenceUrls[] = $competitorUrl;
            }

            $gaps[] = [
                'keyword' => $keyword,
                'intent' => $this->normalizedIntent($gap['intent'] ?? null),
                'site_coverage' => $this->normalizedCoverage($gap['site_coverage'] ?? null),
                'gap_type' => $this->gapType($gap['gap_type'] ?? 'topic'),
                'description' => trim((string) ($gap['description'] ?? $gap['gap'] ?? '')),
                'why_opportunity' => trim((string) ($gap['why_opportunity'] ?? $gap['reason'] ?? '')),
                'recommended_angle' => trim((string) ($gap['recommended_angle'] ?? $gap['suggested_angle'] ?? '')),
                'recommended_title' => trim((string) ($gap['recommended_title'] ?? $gap['title'] ?? '')),
                'recommended_format' => trim((string) ($gap['recommended_format'] ?? 'راهنمای جامع')), 
                'competitor_domain' => $competitorDomain !== '' ? $competitorDomain : null,
                'competitor_url' => $competitorUrl !== '' ? $competitorUrl : null,
                'competitor_title' => trim((string) ($gap['competitor_page_title'] ?? $gap['competitor_title'] ?? '')) ?: null,
                'competitor_approach' => trim((string) ($gap['competitor_approach'] ?? $gap['reason'] ?? '')),
                'site_gap' => trim((string) ($gap['site_gap'] ?? $gap['gap'] ?? '')),
                'content_outline' => $this->strings($gap['content_outline'] ?? []),
                'competitor_position' => $this->nullableInt($gap['competitor_position'] ?? null),
                'current_site_position' => is_numeric($gap['current_site_position'] ?? null) ? (float) $gap['current_site_position'] : null,
                'priority_score' => $this->score($gap['priority_score'] ?? $gap['opportunity_score'] ?? $this->scoreForThreat($threatLevel)),
                'threat_level' => $threatLevel,
                'evidence_urls' => array_values(array_unique($evidenceUrls)),
            ];
        }

        $siteAnalysis = is_array($result['site_analysis'] ?? null) ? $result['site_analysis'] : [];
        $recommendations = is_array($result['recommendations'] ?? null) ? $result['recommendations'] : [];

        return [
            'summary' => trim((string) ($result['summary'] ?? 'تحلیل شکاف محتوایی بر پایه بررسی سایت و صفحات رقیب انجام شد.')),
            'site_analysis' => [
                'status' => in_array($siteAnalysis['status'] ?? null, ['reviewed', 'partially_reviewed', 'unavailable'], true) ? $siteAnalysis['status'] : 'reviewed',
                'pages_reviewed' => $this->strings($siteAnalysis['pages_reviewed'] ?? []),
                'strengths' => $this->strings($siteAnalysis['strengths'] ?? []),
                'weaknesses' => $this->strings($siteAnalysis['weaknesses'] ?? []),
                'search_visibility' => trim((string) ($siteAnalysis['search_visibility'] ?? '')),
                'content_depth' => trim((string) ($siteAnalysis['content_depth'] ?? '')),
            ],
            'competitors' => $competitors,
            'keyword_gaps' => $gaps,
            'recommendations' => $this->normalizeRecommendations($recommendations),
        ];
    }

    protected function normalizeRecommendations(array $recommendations): array
    {
        $normalized = [];
        foreach ($recommendations as $index => $recommendation) {
            if (is_string($recommendation) && trim($recommendation) !== '') {
                $normalized[] = [
                    'priority' => $index + 1,
                    'title' => 'اقدام پیشنهادی '.($index + 1),
                    'action' => trim($recommendation),
                    'expected_result' => '',
                    'related_keywords' => [],
                ];
                continue;
            }

            if (! is_array($recommendation)) {
                continue;
            }

            $action = trim((string) ($recommendation['action'] ?? $recommendation['description'] ?? ''));
            if ($action === '') {
                continue;
            }

            $normalized[] = [
                'priority' => max(1, (int) ($recommendation['priority'] ?? count($normalized) + 1)),
                'title' => trim((string) ($recommendation['title'] ?? 'اقدام پیشنهادی')),
                'action' => $action,
                'expected_result' => trim((string) ($recommendation['expected_result'] ?? '')),
                'related_keywords' => $this->strings($recommendation['related_keywords'] ?? []),
            ];
        }

        return array_slice($normalized, 0, 7);
    }

    protected function evidenceUrls($value): array
    {
        $urls = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && filter_var($item, FILTER_VALIDATE_URL)) {
                $urls[] = $item;
                continue;
            }

            if (is_array($item) && is_string($item['url'] ?? null) && filter_var($item['url'], FILTER_VALIDATE_URL)) {
                $urls[] = trim($item['url']);
            }
        }

        return array_values(array_unique($urls));
    }

    protected function positiveIntegers($value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($item) => is_numeric($item) && (int) $item > 0)
            ->map(fn ($item) => (int) $item)
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizedThreatLevel($value): string
    {
        $value = mb_strtolower(trim((string) $value));
        if (in_array($value, ['high', 'زیاد', 'بالا', 'critical', 'شدید'], true)) {
            return 'high';
        }
        if (in_array($value, ['medium', 'متوسط', 'میانه'], true)) {
            return 'medium';
        }

        return 'low';
    }

    protected function scoreForThreat(string $level): int
    {
        return match ($level) {
            'high' => 80,
            'medium' => 55,
            default => 25,
        };
    }

    protected function normalizedIntent($value): string
    {
        $value = mb_strtolower(trim((string) $value));
        return match ($value) {
            'commercial', 'تجاری' => 'commercial',
            'transactional', 'تراکنشی' => 'transactional',
            'navigational', 'ناوبری' => 'navigational',
            default => 'informational',
        };
    }

    protected function normalizedCoverage($value): string
    {
        $value = mb_strtolower(trim((string) $value));
        return match ($value) {
            'weak', 'ضعیف' => 'weak',
            'ranking_low', 'رتبه پایین' => 'ranking_low',
            'covered', 'پوشش داده شده' => 'covered',
            default => 'missing',
        };
    }

    protected function taskError(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? null) === 'error_message') {
                return trim((string) ($message['error_message']['message'] ?? $message['message'] ?? 'The Manus task stopped with an error.'));
            }
        }

        return 'تسک Manus با خطا متوقف شد.';
    }

    protected function waitingError(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['type'] ?? null) !== 'status_update') {
                continue;
            }

            $detail = $message['status_update']['status_detail'] ?? [];
            return 'تسک Manus برای ادامه منتظر اقدام است: '.trim((string) ($detail['waiting_description'] ?? 'اقدام تأیید لازم است.'));
        }

        return 'تسک Manus برای ادامه منتظر اقدام است.';
    }

    protected function resultSchema(): array
    {
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];
        $nullableString = ['type' => ['string', 'null']];
        $nullableInteger = ['type' => ['integer', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => ['type' => 'string'],
                'site_analysis' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['reviewed', 'partially_reviewed', 'unavailable']],
                        'pages_reviewed' => $stringList,
                        'strengths' => $stringList,
                        'weaknesses' => $stringList,
                        'search_visibility' => ['type' => 'string'],
                        'content_depth' => ['type' => 'string'],
                    ],
                    'required' => ['status', 'pages_reviewed', 'strengths', 'weaknesses', 'search_visibility', 'content_depth'],
                ],
                'competitors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'domain' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                            'keywords' => $stringList,
                            'competitor_page_title' => ['type' => 'string'],
                            'competitor_page_url' => $nullableString,
                            'what_they_cover' => ['type' => 'string'],
                            'content_strengths' => $stringList,
                            'missing_on_site' => $stringList,
                            'evidence_urls' => $stringList,
                            'ranking_positions' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'best_position' => $nullableInteger,
                            'visibility_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'threat_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'threat_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        ],
                        'required' => ['domain', 'name', 'reason', 'keywords', 'competitor_page_title', 'competitor_page_url', 'what_they_cover', 'content_strengths', 'missing_on_site', 'evidence_urls', 'ranking_positions', 'best_position', 'visibility_score', 'threat_level', 'threat_score'],
                    ],
                ],
                'keyword_gaps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'keyword' => ['type' => 'string'],
                            'intent' => ['type' => 'string', 'enum' => ['informational', 'commercial', 'transactional', 'navigational']],
                            'site_coverage' => ['type' => 'string', 'enum' => ['missing', 'weak', 'ranking_low', 'covered']],
                            'gap_type' => ['type' => 'string', 'enum' => ['topic', 'position', 'format', 'intent', 'freshness', 'trust']],
                            'description' => ['type' => 'string'],
                            'why_opportunity' => ['type' => 'string'],
                            'recommended_angle' => ['type' => 'string'],
                            'recommended_title' => ['type' => 'string'],
                            'recommended_format' => ['type' => 'string'],
                            'competitor_domain' => $nullableString,
                            'competitor_url' => $nullableString,
                            'competitor_title' => $nullableString,
                            'competitor_approach' => ['type' => 'string'],
                            'site_gap' => ['type' => 'string'],
                            'content_outline' => $stringList,
                            'competitor_position' => $nullableInteger,
                            'current_site_position' => $nullableNumber,
                            'priority_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'threat_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'evidence_urls' => $stringList,
                        ],
                        'required' => ['keyword', 'intent', 'site_coverage', 'gap_type', 'description', 'why_opportunity', 'recommended_angle', 'recommended_title', 'recommended_format', 'competitor_domain', 'competitor_url', 'competitor_title', 'competitor_approach', 'site_gap', 'content_outline', 'competitor_position', 'current_site_position', 'priority_score', 'threat_level', 'evidence_urls'],
                    ],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'priority' => ['type' => 'integer', 'minimum' => 1],
                            'title' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                            'expected_result' => ['type' => 'string'],
                            'related_keywords' => $stringList,
                        ],
                        'required' => ['priority', 'title', 'action', 'expected_result', 'related_keywords'],
                    ],
                ],
            ],
            'required' => ['summary', 'site_analysis', 'competitors', 'keyword_gaps', 'recommendations'],
        ];
    }

    protected function competitors(array $result): array
    {
        return is_array($result['competitors'] ?? null) ? $result['competitors'] : [];
    }

    protected function keywordGaps(array $result): array
    {
        return is_array($result['keyword_gaps'] ?? null) ? $result['keyword_gaps'] : [];
    }

    protected function apiKey(): string
    {
        $key = (string) (config('services.manus.api_key') ?: env('MANUS_API_KEY'));
        if ($key === '') {
            throw new RuntimeException('MANUS_API_KEY is not configured.');
        }

        return $key;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) (config('services.manus.base_url') ?: env('MANUS_API_BASE', 'https://api.manus.ai')), '/');
    }

    protected function apiError($response, string $fallback): string
    {
        return trim((string) ($response->json('error.message') ?: $response->json('message') ?: $fallback));
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

    protected function threatLevel($value, int $score): string
    {
        if (in_array($value, ['low', 'medium', 'high'], true)) {
            return $value;
        }

        return $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');
    }

    protected function gapType($value): string
    {
        return in_array($value, ['topic', 'position', 'format', 'intent', 'freshness', 'trust'], true)
            ? $value
            : 'topic';
    }

    protected function nullableInt($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
