<?php

namespace App\Services;

use App\Models\ArticleIdea;
use App\Models\ArticleIdeaRelatedLink;
use App\Models\Site;
use App\Models\WordpressArticle;
use App\Services\AI\Concerns\UsesGapgptApiKey;
use App\Services\AI\RecordsTokenUsage;
use App\Support\PageUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تشخیص لینک‌های داخلی مرتبط بین ArticleIdea و مقالات وردپرسی موجود سایت (site_wordpress_articles).
 *
 * معماری دو مرحله‌ای (هم‌خانواده با assignTaxonomy در ArticleIdeaService):
 *  1) Shortlist محلی و رایگان: با تطابق واژگانی نرمال‌شده‌ی فارسی، برای هر idea چند تا
 *     candidate برتر انتخاب می‌شه (بدون AI، سریع).
 *  2) انتخاب نهایی با AI: چون تطابق واژگانی فقط کلمه‌ی مشترک رو می‌بینه نه ارتباط معنایی
 *     (مثلاً «علائم کمبود آهن» با «چرا همیشه خسته‌اید» هیچ کلمه‌ی مشترکی ندارن ولی کاملاً
 *     مرتبطن)، یک AI call سبک و batched (یک call برای کل ideaهای یک سایت، نه به‌ازای هر idea)
 *     از بین shortlist محلی، فقط مقالاتی که واقعاً از نظر موضوعی مرتبطن رو انتخاب می‌کنه.
 *
 * اگر AI در دسترس نبود/خطا داد، به‌صورت non-fatal به یک fallback واژگانیِ سخت‌گیرانه
 * (آستانه‌ی بالا، بدون حداقل اجباری) برمی‌گردیم؛ هرگز لینک ضعیف به‌زور نمی‌چسبونیم.
 */
class RelatedArticleLinkService
{
    use RecordsTokenUsage;
    use UsesGapgptApiKey;

    /**
     * مدل AI برای انتخاب نهایی. برخلاف تاکسونومی، اینجا reasoning-token bloat وجود ندارد
     * (خروجی معمولاً چند ده تا حداکثر چند صد توکنه)، پس مشکل صرفاً قیمت واحد مدله، نه تعداد
     * توکن خروجی. gpt-4o-mini یک مدل non-reasoning و کاملاً پشتیبانی‌شده برای Structured
     * Outputs/strict json_schema است؛ برای یک تسک انتخاب‌از-بین-چند-گزینه با schema بسته
     * (enum روی idهای واقعی shortlist) کاملاً کافیه و در مقایسه با gpt-5.4-mini حدود ۵ برابر
     * در ورودی و ۷.۵ برابر در خروجی ارزون‌تره. توجه: چون non-reasoning است، برخلاف تاکسونومی
     * پارامترهای reasoning_effort/verbosity اینجا لازم نیست (و بعضی gatewayها ممکنه با آن‌ها
     * روی این مدل خطا بدهند)، پس عمداً اضافه نشده.
     */
    protected const AI_MODEL = 'gpt-4o-mini';

    /** حداکثر لینک نهایی به‌ازای هر idea. دیگر حداقلِ اجباری نداریم — صفر لینک بهتر از لینک بی‌ربطه. */
    protected const MAX_LINKS = 3;

    /** قبل از AI، از بین بهترین‌های این تعداد کاندیدِ محلی انتخاب می‌کنیم. */
    protected const CANDIDATE_POOL_SIZE = 10;

    /** حداقل امتیاز واژگانی برای ورود به shortlist (فیلتر نویز قبل از رسیدن به AI). */
    protected const MIN_SHORTLIST_SCORE = 3;

    /** اگر AI در دسترس نبود، فقط candidateهایی با این امتیاز یا بالاتر به‌صورت fallback چسبونده می‌شن. */
    protected const FALLBACK_MIN_SCORE = 10;

    /** تعداد تلاش برای AI call. */
    protected const MAX_ATTEMPTS = 2;

    protected const STOPWORDS = [
        'و', 'در', 'به', 'از', 'که', 'با', 'را', 'این', 'آن', 'برای', 'تا', 'یا', 'هم', 'است', 'هست',
        'های', 'ها', 'یک', 'می', 'بر', 'چه', 'چرا', 'چگونه', 'چطور', 'کدام', 'بهترین', 'راهنمای',
        'راهنما', 'کامل', 'معرفی', 'بررسی', 'نکات', 'آموزش', 'انواع', 'تمام', 'همه', 'باید', 'شود',
        'کنید', 'کردن', 'دارد', 'شما', 'بدون', 'روی', 'بین', 'مورد', 'اگر',
    ];

    /**
     * برای همه‌ی ideaهای یک سایت (یک batch) در یک فراخوانی کار می‌کنه — نه یکی‌یکی — تا
     * هم query تکراری به WordpressArticle حذف بشه، هم AI call به‌جای N بار، یک بار زده بشه.
     *
     * @param  Collection<int, ArticleIdea>  $ideas
     * @return array<int, int> نگاشت idea_id => تعداد لینک‌های ذخیره‌شده
     */
    public function attachForSite(Site $site, Collection $ideas): array
    {
        $results = [];
        foreach ($ideas as $idea) {
            $results[$idea->id] = 0;
        }

        if ($ideas->isEmpty()) {
            return $results;
        }

        // سرچ کنسول (اگر وصل باشد): هر صفحه در گوگل برای چه جستجوهایی دیده می‌شود؛ بدون هزینه‌ی AI
        $articles = $this->loadNormalizedArticles($site, app(SearchConsoleInsightsService::class)->pageQueryIndex($site));

        if ($articles->isEmpty()) {
            return $results;
        }

        // مرحله ۱: shortlist محلی برای هر idea (بدون AI)
        $shortlists = [];
        foreach ($ideas as $idea) {
            $shortlist = $this->buildShortlist($idea, $articles);

            if ($shortlist->isNotEmpty()) {
                $shortlists[$idea->id] = $shortlist;
            }
        }

        if (empty($shortlists)) {
            Log::info('RelatedArticleLinkService: no lexical candidates for any idea in site', [
                'site_id' => $site->id,
            ]);

            return $results;
        }

        // مرحله ۲: انتخاب نهایی با AI (یک call برای کل سایت)
        $aiSelections = $this->requestAiSelections($site, $ideas, $shortlists);

        foreach ($ideas as $idea) {
            if (! isset($shortlists[$idea->id])) {
                continue;
            }

            $shortlist = $shortlists[$idea->id];

            $chosen = $aiSelections !== null
                ? $this->resolveAiSelection($idea, $shortlist, $aiSelections[$idea->id] ?? [])
                : $this->fallbackLexicalSelection($shortlist);

            if ($chosen->isEmpty()) {
                continue;
            }

            $results[$idea->id] = $this->persist($idea, $site, $chosen);
        }

        return $results;
    }

    /**
     * سازگاری با کد قدیمی: یک idea تکی. داخلاً از همون attachForSite استفاده می‌کنه.
     * برای batch واقعی (چند idea با هم) به‌جاش از attachForSite استفاده کن، چون یک AI
     * call مشترک برای کل batch می‌زنه و ارزون‌تر و سریع‌تره.
     */
    public function attach(ArticleIdea $idea): int
    {
        $site = $idea->website ?? Site::find($idea->website_id);

        if (! $site) {
            return 0;
        }

        $result = $this->attachForSite($site, collect([$idea]));

        return $result[$idea->id] ?? 0;
    }

    /**
     * مقالات وردپرسی سایت رو یک‌بار می‌خونه و نسخه‌ی نرمال‌شده‌ی متن هر کدوم رو (برای
     * تطابق واژگانی) از قبل می‌سازه تا در حلقه‌ی امتیازدهی تکراراً محاسبه نشه.
     */
    protected function loadNormalizedArticles(Site $site, array $gscIndex = []): Collection
    {
        return WordpressArticle::where('site_id', $site->id)
            ->get()
            ->map(function (WordpressArticle $article) use ($gscIndex) {
                $gsc = $gscIndex[PageUrl::hash($article->url)] ?? ['queries' => [], 'position' => null];
                $categoryNames = collect($article->categories ?? [])
                    ->pluck('name')
                    ->filter()
                    ->map(fn ($n) => trim(html_entity_decode((string) $n, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

                $tagNames = collect($article->tags ?? [])
                    ->pluck('name')
                    ->filter()
                    ->map(fn ($n) => trim(html_entity_decode((string) $n, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

                $title = trim(html_entity_decode((string) $article->title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return [
                    'article' => $article,
                    'title' => $title,
                    'category_names' => $categoryNames->values(),
                    'tag_names' => $tagNames->values(),
                    'title_tokens' => $this->meaningfulTokens($this->normalizePersianText($title)),
                    'taxonomy_tokens' => $this->meaningfulTokens(
                        $this->normalizePersianText($categoryNames->implode(' ').' '.$tagNames->implode(' '))
                    ),
                    'normalized_title' => $this->normalizePersianText($title),
                    'gsc_queries' => $gsc['queries'],
                    'gsc_position' => $gsc['position'],
                    'query_tokens' => $this->meaningfulTokens($this->normalizePersianText(implode(' ', $gsc['queries']))),
                ];
            });
    }

    /**
     * برای یک idea، بهترین CANDIDATE_POOL_SIZE مقاله رو بر اساس امتیاز واژگانی برمی‌گردونه.
     * از تمام سیگنال idea (نه فقط عنوان) استفاده می‌کنه: title + meta + hook + outline + entity.
     */
    protected function buildShortlist(ArticleIdea $idea, Collection $articles): Collection
    {
        $ideaText = $this->ideaSearchText($idea);
        $ideaTokens = $this->meaningfulTokens($ideaText);
        $entityName = trim((string) ($idea->entity_name ?? ''));
        $normalizedEntity = $this->normalizePersianText($entityName);
        $normalizedIdeaTitle = $this->normalizePersianText((string) $idea->title);

        if (empty($ideaTokens) && $normalizedEntity === '') {
            return collect();
        }

        return $articles
            ->reject(function ($row) use ($normalizedIdeaTitle) {
                // جلوگیری از پیشنهاد یک مقاله با همان عنوان idea (خودلینکی/تکراری).
                return $normalizedIdeaTitle !== '' && $row['normalized_title'] === $normalizedIdeaTitle;
            })
            ->map(function ($row) use ($ideaTokens, $normalizedEntity) {
                return [
                    'row' => $row,
                    'score' => $this->scoreArticle($ideaTokens, $normalizedEntity, $row),
                ];
            })
            ->filter(fn ($r) => $r['score'] >= self::MIN_SHORTLIST_SCORE)
            ->sortByDesc('score')
            ->take(self::CANDIDATE_POOL_SIZE)
            ->values();
    }

    /**
     * امتیازدهی واژگانی:
     *  +8  اگر entity_name عیناً به‌عنوان یک دسته/تگ مقاله دیده بشه (فقط برای entityهای
     *      حداقل ۴ کاراکتری، تا substring کوتاه false-positive نده)
     *  +3  به‌ازای هر توکن معنادار مشترک بین idea و category/tag مقاله (سیگنال قوی‌تر)
     *  +1.5 به‌ازای هر توکن معنادار مشترک بین idea و عنوان مقاله (سیگنال ضعیف‌تر)
     * خروجی صحیح (int) با round است.
     */
    protected function scoreArticle(array $ideaTokens, string $normalizedEntity, array $articleRow): int
    {
        $score = 0;

        if ($normalizedEntity !== '' && mb_strlen($normalizedEntity) >= 4) {
            $matchesTerm = $articleRow['category_names']->concat($articleRow['tag_names'])
                ->contains(function ($name) use ($normalizedEntity) {
                    return str_contains($this->normalizePersianText($name), $normalizedEntity);
                });

            if ($matchesTerm) {
                $score += 8;
            }
        }

        $sharedTaxonomy = array_intersect($ideaTokens, $articleRow['taxonomy_tokens']);
        $score += count($sharedTaxonomy) * 3;

        $sharedTitle = array_intersect($ideaTokens, $articleRow['title_tokens']);
        $score += (int) round(count($sharedTitle) * 1.5);

        // جستجوهایی که گوگل این صفحه را برایشان نشان می‌دهد: نشانه‌ی واقعی موضوع صفحه
        $sharedQueries = array_intersect($ideaTokens, $articleRow['query_tokens'] ?? []);
        $score += count($sharedQueries) * 2;

        // صفحه‌ی رتبه‌ی ۴ تا ۲۰ بیشترین سود را از لینک داخلی می‌برد؛ فقط وقتی خودش مرتبط باشد
        $position = $articleRow['gsc_position'] ?? null;
        if ($score >= self::MIN_SHORTLIST_SCORE && $position !== null && $position >= 4 && $position <= 20) {
            $score += 2;
        }

        return $score;
    }

    /**
     * AI call تک‌مرحله‌ای برای کل سایت: به‌ازای هر idea که shortlist محلی داره، از بین
     * candidateهای همون shortlist می‌پرسه کدوم‌ها واقعاً از نظر موضوعی مرتبط‌ان.
     * idهای مقالات در schema به‌صورت enum (اجتماع همه‌ی shortlistها) محدود می‌شن تا AI
     * نتونه idِ ساختگی برگردونه؛ بعداً هم عضویت هر id در shortlist همون idea چک می‌شه.
     *
     * @param  Collection<int, ArticleIdea>  $ideas
     * @param  array<int, Collection>  $shortlists  idea_id => Collection<{row, score}>
     * @return array<int, array<int, array{article_id:int, reason:string}>>|null idea_id => selections، یا null اگه AI شکست خورد
     */
    protected function requestAiSelections(Site $site, Collection $ideas, array $shortlists): ?array
    {
        $ideasWithCandidates = $ideas->filter(fn ($idea) => isset($shortlists[$idea->id]))->values();

        if ($ideasWithCandidates->isEmpty()) {
            return null;
        }

        $allArticleIds = collect($shortlists)
            ->flatMap(fn ($shortlist) => $shortlist->pluck('row.article.id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $payload = [
            'model' => self::AI_MODEL,
            // 'temperature' => 0,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'related_article_links',
                    'strict' => true,
                    'schema' => $this->buildAiSchema($ideasWithCandidates->count(), $allArticleIds),
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => $this->aiSystemPrompt()],
                ['role' => 'user', 'content' => $this->buildAiUserContent($ideasWithCandidates, $shortlists)],
            ],
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->callGapgptChat($payload);
            } catch (\Throwable $exception) {
                Log::warning('RelatedArticleLinkService: AI call threw exception', [
                    'site_id' => $site->id,
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if (! $response || ! $response->successful()) {
                Log::warning('RelatedArticleLinkService: AI request failed', [
                    'site_id' => $site->id,
                    'attempt' => $attempt,
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                ]);

                continue;
            }

            $message = $response->json('choices.0.message.content');
            $parsed = is_string($message) ? json_decode($message, true) : null;

            if (! is_array($parsed) || ! isset($parsed['assignments']) || ! is_array($parsed['assignments'])) {
                Log::warning('RelatedArticleLinkService: AI response not parsable', [
                    'site_id' => $site->id,
                    'attempt' => $attempt,
                    'message' => $message,
                ]);

                continue;
            }

            $usage = $response->json('usage');
            if ($usage && $site->user) {
                $this->recordAiTokenUsage(
                    user: $site->user,
                    serviceName: 'ai.related_article_links',
                    usage: $usage,
                    model: self::AI_MODEL,
                );
            }

            // idea_index (اندیس داخل $ideasWithCandidates) => آرایه‌ی انتخاب‌ها
            $byIdeaIndex = collect($parsed['assignments'])->keyBy(fn ($a) => (int) ($a['idea_index'] ?? -1));

            $result = [];
            foreach ($ideasWithCandidates->values() as $index => $idea) {
                $assignment = $byIdeaIndex->get($index);
                $result[$idea->id] = collect($assignment['selected'] ?? [])
                    ->map(fn ($s) => [
                        'article_id' => (int) ($s['article_id'] ?? 0),
                        'reason' => (string) ($s['reason'] ?? ''),
                    ])
                    ->all();
            }

            return $result;
        }

        return null;
    }

    protected function buildAiSchema(int $ideasCount, array $allArticleIds): array
    {
        $articleIdSchema = ['type' => 'integer'];
        if (! empty($allArticleIds)) {
            $articleIdSchema['enum'] = $allArticleIds;
        }

        return [
            'type' => 'object',
            'properties' => [
                'assignments' => [
                    'type' => 'array',
                    'minItems' => $ideasCount,
                    'maxItems' => $ideasCount,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'idea_index' => ['type' => 'integer'],
                            'selected' => [
                                'type' => 'array',
                                'maxItems' => self::MAX_LINKS,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'article_id' => $articleIdSchema,
                                        'reason' => [
                                            'type' => 'string',
                                            'description' => 'حداکثر ۱۰ کلمه: چرا این مقاله برای لینک داخلی مرتبط است.',
                                        ],
                                    ],
                                    'required' => ['article_id', 'reason'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['idea_index', 'selected'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['assignments'],
            'additionalProperties' => false,
        ];
    }

    protected function aiSystemPrompt(): string
    {
        return <<<'PROMPT'
شما یک متخصص لینک‌سازی داخلی وبلاگ هستید. برای هر ایده‌ی مقاله، از بین «Candidate Articles» همان
ایده، فقط مقالاتی را انتخاب کن که از نظر موضوعی واقعاً به idea مرتبط‌اند و ارجاع به آن‌ها برای
خواننده طبیعی و مفید است.

معیار ارتباط موضوعی، نه صرفاً اشتراک کلمه:
- دو متن ممکن است هیچ کلمه‌ی مشترکی نداشته باشند ولی موضوعاً کاملاً مرتبط باشند (مثلاً یکی درباره
  «علائم کمبود آهن» و دیگری درباره «چرا همیشه خسته‌اید» — این‌ها مرتبط‌اند).
- برعکس، دو متن ممکن است کلمات مشترک داشته باشند ولی موضوعاً بی‌ربط باشند؛ این‌ها را انتخاب نکن.
- «ranks in Google for» یعنی گوگل آن صفحه را برای همین جستجوها نشان می‌دهد؛ این نشانه‌ی واقعی موضوع صفحه است.
  اگر این جستجوها به idea نزدیک‌اند، آن صفحه انتخاب خوبی است؛ صفحه‌ای با رتبه‌ی ۴ تا ۲۰ بیشترین سود را از لینک می‌برد.
- فقط از میان idهای Candidate همان idea انتخاب کن؛ هرگز id از idea دیگر یا id ساختگی نده.
- اگر هیچ candidate واقعاً مرتبط نبود، selected را آرایه‌ی خالی بگذار. تعداد لینک مهم نیست، ارتباط
  واقعی مهم است — حداکثر ۳ مورد، ولی هرگز برای رسیدن به حداقل، مقاله‌ی کم‌ربط انتخاب نکن.
- reason را در حداکثر ۱۰ کلمه بنویس.
- idea_index دقیقاً همان اندیسی باشد که در ورودی آمده.
- خروجی فقط JSON معتبر مطابق Schema باشد، بدون هیچ متن اضافه.
PROMPT;
    }

    /**
     * @param  Collection<int, ArticleIdea>  $ideas
     * @param  array<int, Collection>  $shortlists
     */
    protected function buildAiUserContent(Collection $ideas, array $shortlists): string
    {
        $lines = [];

        foreach ($ideas->values() as $index => $idea) {
            $outline = collect($idea->outline ?? [])->filter()->implode(' | ');

            $lines[] = "Idea {$index}:";
            $lines[] = '  title: '.$idea->title;
            $lines[] = '  entity: '.($idea->entity_name ?? '-');
            $lines[] = '  meta: '.($idea->meta_description ?? '');

            if (! empty($idea->hook)) {
                $lines[] = '  hook: '.$idea->hook;
            }

            if ($outline !== '') {
                $lines[] = '  outline: '.$outline;
            }

            $lines[] = '  Candidate Articles:';

            foreach ($shortlists[$idea->id] as $candidate) {
                $row = $candidate['row'];
                $categories = $row['category_names']->implode(', ');
                $tags = $row['tag_names']->implode(', ');

                $lines[] = "  - id: {$row['article']->id} | title: {$row['title']}"
                    .($categories !== '' ? " | categories: {$categories}" : '')
                    .($tags !== '' ? " | tags: {$tags}" : '')
                    .($row['gsc_queries'] !== [] ? ' | ranks in Google for: '.implode('، ', $row['gsc_queries'])
                        .($row['gsc_position'] !== null ? " (avg position {$row['gsc_position']})" : '') : '');
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * خروجی AI رو نسبت به shortlist واقعیِ همون idea اعتبارسنجی می‌کنه (حتی اگه enum سراسری
     * هم id رو مجاز دونسته باشه، اینجا مطمئن می‌شیم واقعاً برای همین idea shortlist شده بود،
     * نه یک idea دیگه در همون batch).
     *
     * @return Collection<int, array{row: array, score: int, reason: string}>
     */
    protected function resolveAiSelection(ArticleIdea $idea, Collection $shortlist, array $selections): Collection
    {
        $rowsById = $shortlist->keyBy(fn ($c) => (int) $c['row']['article']->id);

        return collect($selections)
            ->map(function ($s) use ($rowsById, $idea) {
                $articleId = (int) ($s['article_id'] ?? 0);

                if (! $rowsById->has($articleId)) {
                    Log::warning('RelatedArticleLinkService: AI selected article outside idea shortlist', [
                        'idea_id' => $idea->id,
                        'article_id' => $articleId,
                    ]);

                    return null;
                }

                $candidate = $rowsById->get($articleId);

                return [
                    'row' => $candidate['row'],
                    'score' => $candidate['score'],
                    'reason' => $s['reason'] ?? '',
                ];
            })
            ->filter()
            ->take(self::MAX_LINKS)
            ->values();
    }

    /**
     * وقتی AI در دسترس نیست/خطا داده: فقط بهترین‌های shortlist که امتیاز واژگانیِ واقعاً قوی
     * دارن (نه هر چیزی بالای صفر) رو نگه می‌داریم. هیچ حداقل اجباری‌ای اعمال نمی‌شه.
     */
    protected function fallbackLexicalSelection(Collection $shortlist): Collection
    {
        return $shortlist
            ->filter(fn ($c) => $c['score'] >= self::FALLBACK_MIN_SCORE)
            ->take(self::MAX_LINKS)
            ->map(fn ($c) => ['row' => $c['row'], 'score' => $c['score'], 'reason' => null]);
    }

    /**
     * @param  Collection<int, array{row: array, score: int, reason: ?string}>  $chosen
     */
    protected function persist(ArticleIdea $idea, Site $site, Collection $chosen): int
    {
        DB::transaction(function () use ($chosen, $idea, $site) {
            foreach ($chosen as $item) {
                /** @var WordpressArticle $article */
                $article = $item['row']['article'];

                ArticleIdeaRelatedLink::updateOrCreate(
                    [
                        'article_idea_id' => $idea->id,
                        'wordpress_article_id' => $article->id,
                    ],
                    [
                        'site_id' => $site->id,
                        'url' => $article->url,
                        'title' => $item['row']['title'],
                        'anchor_query' => $this->anchorQuery($idea, $item['row']['gsc_queries']),
                        'match_score' => $item['score'],
                    ]
                );
            }
        });

        if ($chosen->contains(fn ($c) => ! empty($c['reason']))) {
            Log::info('RelatedArticleLinkService: links attached with AI reasoning', [
                'idea_id' => $idea->id,
                'links' => $chosen->map(fn ($c) => [
                    'title' => $c['row']['title'],
                    'reason' => $c['reason'],
                ])->all(),
            ]);
        }

        return $chosen->count();
    }

    /**
     * پیشنهاد متن لینک: جستجویی از صفحه‌ی مقصد که بیشترین کلمه‌ی مشترک را با idea دارد.
     * متن لینکی که با جستجوی واقعیِ صفحه‌ی مقصد یکی است، موضوع آن صفحه را به گوگل روشن‌تر نشان می‌دهد.
     *
     * @param  array<int, string>  $queries  پرنمایش‌ترین اول
     */
    protected function anchorQuery(ArticleIdea $idea, array $queries): ?string
    {
        $ideaTokens = $this->meaningfulTokens($this->ideaSearchText($idea));
        $best = null;
        $bestShared = 0;

        foreach ($queries as $query) {
            $shared = count(array_intersect($ideaTokens, $this->meaningfulTokens($this->normalizePersianText($query))));

            if ($shared > $bestShared) {
                [$best, $bestShared] = [$query, $shared];
            }
        }

        return $best !== null ? mb_substr($best, 0, 255) : null;
    }

    protected function ideaSearchText(ArticleIdea $idea): string
    {
        $parts = [
            $idea->title ?? '',
            $idea->meta_description ?? '',
            $idea->hook ?? '',
            $idea->entity_name ?? '',
            collect($idea->outline ?? [])->filter()->implode(' '),
        ];

        return $this->normalizePersianText(implode(' ', $parts));
    }

    /**
     * نرمال‌سازی متن فارسی: حذف نیم‌فاصله/اعراب، یکسان‌سازی ی/ک عربی، حذف نشانه‌گذاری.
     */
    public function normalizePersianText(string $text): string
    {
        $text = str_replace(["\u{200c}", "\u{200d}", "\u{200e}", "\u{200f}"], ' ', $text);

        $text = strtr($text, [
            'ي' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ؤ' => 'و', 'ئ' => 'ی', '٠' => '0', '١' => '1', '٢' => '2',
            '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim(mb_strtolower($text, 'UTF-8'));
    }

    /**
     * @return array<int, string>
     */
    public function meaningfulTokens(string $normalizedText): array
    {
        if ($normalizedText === '') {
            return [];
        }

        return collect(explode(' ', $normalizedText))
            ->filter(fn ($token) => mb_strlen($token) >= 3 && ! in_array($token, self::STOPWORDS, true))
            ->unique()
            ->values()
            ->all();
    }
}
