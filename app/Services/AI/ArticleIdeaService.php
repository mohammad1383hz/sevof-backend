<?php

namespace App\Services\AI;

use App\Services\TopicHistory;
use App\Models\WordpressArticle;
use App\Models\Article;
use App\Models\ArticleIdea;
use App\Models\Site;
use App\Models\WebsiteEntity;
use App\Services\Research\EntityKeywordSuggestionService;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ArticleIdeaService
{
    use RecordsTokenUsage;
    use \App\Services\AI\Concerns\UsesGapgptApiKey;

    protected const ENTITY_SCOPES = ['primary', 'core'];

    /**
     * مدلی که برای taxonomy واقعاً صدا زده می‌شود و دقیقاً همین هم لاگ می‌شود.
     *
     * عمداً روی nano تنظیم شده، نه mini: taxonomy یک classification با enum بسته روی idهای
     * واقعی است، یعنی خودِ json_schema تضمین می‌کند که مدل هرگز id نامعتبر برنگرداند. بخش سخت
     * کار (اعتبار id) ساختاری حل شده، نه وابسته به قدرت استدلال مدل؛ پس مدل سبک‌تر هزینه را به‌شدت
     * پایین می‌آورد بدون افت محسوس در دقت انتخاب. اگر در تست عملی دقت افت کرد، برگردوندن به
     * 'gpt-5.4-mini' کافیست (فقط همین ثابت را عوض کنید، جای دیگری نیاز به تغییر ندارد).
     */
    protected const TAXONOMY_MODEL = 'gpt-4o-mini';

    /** حداکثر تگ برای هر مقاله. */
    protected const TAXONOMY_MAX_TAGS = 3;

    /** سقف تعداد category/tag که داخل پرامپت فرستاده می‌شود (بالاتر از این، shortlist می‌شود). */
    protected const TAXONOMY_MAX_CATEGORIES_IN_PROMPT = 40;
    protected const TAXONOMY_MAX_TAGS_IN_PROMPT = 45;

    /** برای هر idea حداکثر چند کاندید از لیست بزرگ برداشته شود. */
    protected const TAXONOMY_PER_IDEA_CANDIDATES = 15;

    /** حداقل امتیاز تطابق متنی برای fallback خودکار وقتی AI چیزی انتخاب نکرده. */
    protected const TAXONOMY_FALLBACK_MIN_CATEGORY_SCORE = 6;
    protected const TAXONOMY_FALLBACK_MIN_TAG_SCORE = 10;

    /** تعداد تلاش برای AI call (پاسخ نامعتبر/خطای موقت). */
    protected const TAXONOMY_MAX_ATTEMPTS = 2;

    /** کلمات پرتکرار فارسی که در امتیازدهی تطابق نباید وزن بگیرند. */
    protected const TAXONOMY_STOPWORDS = [
        'و', 'در', 'به', 'از', 'که', 'با', 'را', 'این', 'آن', 'برای', 'تا', 'یا', 'هم', 'است', 'هست',
        'های', 'ها', 'یک', 'می', 'بر', 'چه', 'چرا', 'چگونه', 'چطور', 'کدام', 'بهترین', 'راهنمای',
        'راهنما', 'کامل', 'معرفی', 'بررسی', 'نکات', 'آموزش', 'انواع', 'تمام', 'همه', 'باید', 'شود',
        'کنید', 'کردن', 'دارد', 'شما', 'بدون', 'روی', 'بین', 'مورد', 'اگر',
    ];

    public function __construct(
        protected EntityKeywordSuggestionService $keywordSuggestionService,
    ) {
    }

    /**
     * @param  array  $keywords  خروجی EntityKeywordSuggestionService::generate() که از قبل
     *                           (توسط FetchEntityKeywordsJob) گرفته و ذخیره شده؛ این سرویس دیگر
     *                           خودش autocomplete/AI صدا نمی‌زند تا در یک request دوباره‌کاری نشود.
     *                           اگر خالی باشد (مثلاً هنوز fetch نشده یا fail کرده)، تولید ایده
     *                           بدون keyword واقعی و فقط بر اساس قضاوت AI انجام می‌شود.
     */
    public function generate(
        Site $site,
        array $keywords = [],
        string $prompt = '',
        ?string $selectedName = null,
        ?string $selectedScope = null,
    ): array
    {
        Log::info('ArticleIdeaService:333 generate started', [
            'site_id' => $site->id ?? null,
            'domain' => $site->domain ?? null,
        ]);

        $entity = $site->ensureEntity();

        if (! $entity || ! $entity->primary_entity) {
            throw new RuntimeException('The site analysis isn\'t ready yet. Try again in a minute or two.');
        }

        // این همون scope (primary + N تا core اول) هست که EntityKeywordSuggestionService هم استفاده می‌کنه؛
        // منبع واحد حقیقت، تا article ideas و keyword research هیچ‌وقت روی entityهای متفاوتی کار نکنن.
        $targets = $this->keywordSuggestionService->buildTargetEntities($site, $selectedName, $selectedScope);
        Log::info('ArticleIdeaService: targets built', [
            'site_id' => $site->id ?? null,
            'targets' => $targets,
        ]);

        if (empty($targets)) {
            throw new RuntimeException('No valid entity was found to generate article ideas.');
        }
        $targets = $this->normalizeTargetScopes($targets, $entity);
        $entitiesBlock = $this->buildEntitiesBlock($entity, $targets);

        Log::info('ArticleIdeaService: keywords received', [
            'site_id' => $site->id ?? null,
            'keywords_count' => count($keywords),
        ]);

        $keywordsBlock = $this->buildKeywordsBlock($keywords);
        $searchConsoleBlock = $this->buildSearchConsoleBlock($site);
        $contentGapsBlock = $this->buildContentGapsBlock($site);
        $producedArticlesBlock = $this->buildProducedArticlesBlock($site, $targets);
        // چند مقاله‌ی آخر خود صاحب سایت: ایده‌ی تازه نباید همان سؤال را دوباره جواب بدهد
        $recentPostsBlock = app(TopicHistory::class)->recentPostsBlock($site);

        $userContent = "Domain: {$site->domain}\nNiche: {$site->niche}\n\n".$entitiesBlock;

        if (trim($prompt) !== '') {
            $userContent .= "\n\nUser content-opportunity request:\n".trim($prompt);
        }

        foreach ([$keywordsBlock, $searchConsoleBlock, $contentGapsBlock, $producedArticlesBlock, $recentPostsBlock] as $evidenceBlock) {
            if ($evidenceBlock !== '') {
                $userContent .= "\n\n".$evidenceBlock;
            }
        }

        Log::info('ArticleIdeaService: calling AI for idea generation', [
            'site_id' => $site->id ?? null,
            'ideas_count_requested' => $this->ideasCount(),
            'model' => 'gpt-5.4-mini',
            'user_content_length' => mb_strlen($userContent),
        ]);

        $response = $this->callGapgptChat([
            'model' => 'gpt-5.4-mini',
            // The inexpensive gpt-5-nano is reserved for entity routing;
            // this generation call keeps the existing quality model.
            // gpt-5-mini
            // gpt-5.2
            // gpt-5.4-mini
            'temperature' => 0.6,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'article_ideas',
                    'strict' => true,
                    'schema' => $this->buildSchema($this->ideasCount()),
                ],
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt($this->ideasCount(), ContentLanguage::forSite($site)),
                ],
                [
                    'role' => 'user',
                    'content' => $userContent,
                ],
            ],
        ]);

        if (! $response || ! $response->successful()) {
            Log::error('ArticleIdeaService: AI request failed', [
                'site_id' => $site->id ?? null,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);

            throw new RuntimeException('Article idea generation failed: '.($response?->body() ?? 'No active API key responded.'));
        }

        $message = $response->json('choices.0.message.content');

        if (! $message) {
            Log::error('ArticleIdeaService: AI returned empty message', [
                'site_id' => $site->id ?? null,
                'raw_response' => $response->json(),
            ]);

            throw new RuntimeException('No response from the AI.');
        }

        Log::info('ArticleIdeaService: AI raw message received', [
            'site_id' => $site->id ?? null,
            'message_length' => mb_strlen($message),
        ]);

        $parsed = json_decode($message, true);

        if (! is_array($parsed) || ! isset($parsed['ideas']) || ! is_array($parsed['ideas'])) {
            Log::error('ArticleIdeaService: AI response not parsable', [
                'site_id' => $site->id ?? null,
                'message' => $message,
                'json_last_error' => json_last_error_msg(),
            ]);

            throw new RuntimeException('The AI response couldn\'t be processed.');
        }

        $usage = $response->json('usage');
        if ($usage && $site->user) {
            $this->recordAiTokenUsage(
                user: $site->user,
                serviceName: 'ai.article_idea_generation',
                usage: $usage,
                model: 'gpt-5.4-mini',
            );
        }

        // نکته‌ی کلیدی: اینجا هر idea رو نسبت به لینک‌های واقعیِ همون entity اعتبارسنجی می‌کنیم.
        $validated = $this->validateIdeas($parsed['ideas'], $entity, $targets);

        Log::info('ArticleIdeaService: ideas validated', [
            'site_id' => $site->id ?? null,
            'raw_ideas_count' => count($parsed['ideas']),
            'validated_ideas_count' => count($validated),
        ]);

        if (empty($validated)) {
            // اگه AI در هر ۵ ایده entity_name/scope رو اشتباه نوشته باشه، validateIdeas همه رو رد می‌کنه.
            // برگردوندن آرایه‌ی خالی به‌صورت ساکت غلطه؛ صدا زننده باید بفهمه که باید retry کنه.
            Log::error('ArticleIdeaService: all ideas rejected during validation', [
                'site_id' => $site->id ?? null,
            ]);

            throw new RuntimeException('No valid ideas came out of the AI. Please try again.');
        }

        // شبکه‌ی ایمنی: ایده‌ای که عملاً همان عنوانِ یک ایده‌ی قبلیِ همین Entity (یا ایده‌ی دیگرِ همین batch) است حذف می‌شود.
        $validated = $this->filterDuplicateIdeas($site, $validated);

        if (empty($validated)) {
            throw new RuntimeException('All generated ideas were duplicates. Please try again.');
        }

        // ⬅️ فقط اگه سایت categories/tags واقعی (از WordPressTaxonomyService) داشته باشه، یک AI
        // call سبک و جدا برای انتخاب category/tag از بین taxonomy موجود سایت زده می‌شه. اگه سایت
        // اصلاً taxonomy نداره (وردپرسی نیست یا هنوز sync نشده)، هیچ AI callای زده نمی‌شه و idea
        // ها بدون تغییر (category/tag خالی) برمی‌گردن.
        $validated = $this->assignTaxonomy($site, $validated);

        // priority نباید فقط حدس AI باشه؛ جایی که keyword واقعی داریم، اون رو هم وارد محاسبه می‌کنیم.
        $result = $this->blendPriorityWithRelevance($validated, $keywords);

        Log::info('ArticleIdeaService: generate finished', [
            'site_id' => $site->id ?? null,
            'final_ideas_count' => count($result),
        ]);

        return $result;
    }

    protected function ideasCount(): int
    {
        return (int)  3;
    }
    protected function normalizeTargetScopes(array $targets, WebsiteEntity $entity): array
{
    $primaryName = $entity->primary_entity['name'] ?? null;

    foreach ($targets as &$target) {
        if (isset($target['scope'])) {
            continue;
        }

        $name = $target['name'] ?? $target['title'] ?? null;

        $target['scope'] = ($primaryName !== null && $name === $primaryName)
            ? 'primary'
            : 'core';
    }
    unset($target);

    return $targets;
}

    /**
     * priority ای که AI داده رو با relevance واقعی (اگه برای اون entity keyword داشتیم) ترکیب می‌کنه.
     * relevance خام‌ها مقیاس ثابتی ندارن (بستگی به حجم جستجو داره)، پس نسبت به بیشترین relevance
     * موجود در همین batch نرمالایز می‌شن (۰ تا ۱۰۰) و بعد با وزن ۴۰٪ با priority خود AI ترکیب می‌شن.
     */
    protected function blendPriorityWithRelevance(array $ideas, array $keywords): array
    {
        if (empty($keywords)) {
            return $ideas;
        }

        $maxRelevance = max(array_map(fn ($k) => (int) ($k['total_relevance'] ?? 0), $keywords));

        if ($maxRelevance <= 0) {
            return $ideas;
        }

        // بالاترین relevance به‌ازای هر entity (ممکنه چند keyword برای یک entity باشه)
        $bestRelevanceByEntity = [];
        foreach ($keywords as $keyword) {
            $key = ($keyword['entity_scope'] ?? '').'|'.($keyword['entity_name'] ?? '');
            $relevance = (int) ($keyword['total_relevance'] ?? 0);
            $bestRelevanceByEntity[$key] = max($bestRelevanceByEntity[$key] ?? 0, $relevance);
        }

        foreach ($ideas as &$idea) {
            $key = ($idea['entity_scope'] ?? '').'|'.($idea['entity_name'] ?? '');

            if (! isset($bestRelevanceByEntity[$key])) {
                continue; // این entity keyword واقعی نداشت، priority همون چیزیه که AI داده
            }

            $normalizedRelevance = (int) round(($bestRelevanceByEntity[$key] / $maxRelevance) * 100);
            $aiPriority = (int) ($idea['priority'] ?? 0);

            $idea['priority'] = (int) round(($aiPriority * 0.6) + ($normalizedRelevance * 0.4));
        }
        unset($idea);

        return $ideas;
    }

    /**
     * جستجوهای واقعی سرچ کنسول: فرصت‌هایی که هیچ صفحه‌ای از سایت در صفحه‌ی اول‌شان نیست، منهای
     * آن‌هایی که مقاله‌ی موجود پوشش داده؛ و جستجوهای قوی که نباید دوباره هدف گرفته شوند.
     */
    protected function buildSearchConsoleBlock(Site $site): string
    {
        $insights = app(\App\Services\SearchConsoleInsightsService::class);

        return $insights->ideaEvidenceBlock($site, $insights->coveredTexts($site));
    }

    protected function buildContentGapsBlock(Site $site): string
    {
        $gaps = DB::table('content_gaps')
            ->where('site_id', $site->id)
            ->whereIn('data_source', ['gsc_justserp', 'manus'])
            ->latest('id')
            ->limit(20)
            ->get(['query', 'title', 'description', 'recommended_angle', 'evidence']);

        if ($gaps->isEmpty()) {
            return '';
        }

        $lines = ['Verified competitor content gaps (Search Console/JustSerp or Manus evidence; use as factual signals):'];
        foreach ($gaps as $gap) {
            $lines[] = '- Query: '.$gap->query.' | competitor result: '.$gap->title.' | gap: '.$gap->description.' | angle: '.$gap->recommended_angle.' | evidence: '.$gap->evidence;
        }

        return implode("\n", $lines);
    }

    /**
     * ایده‌هایی که برای همین Entity (بر اساس $targets: primary/core انتخاب‌شده) قبلاً مقاله‌شون
     * نوشته شده یا در حال نوشتنه (هر وضعیتی جز failed) رو برمی‌گردونه.
     * هدف: جلوگیری از تکرار موضوع/زاویه توسط AI. عمداً همه‌ی ایده‌های suggested به AI داده نمی‌شه
     * (پرامپت سنگین و محدودیت غیرلازم)؛ تکرار با آن‌ها را filterDuplicateIdeas سمت سرور می‌گیرد.
     */
    protected function buildProducedArticlesBlock(Site $site, array $targets): string
    {
        $entityPairs = collect($targets)
            ->map(fn ($t) => [$t['scope'] ?? null, $t['name'] ?? ($t['title'] ?? null)])
            ->filter(fn ($pair) => $pair[0] !== null && $pair[1] !== null);

        if ($entityPairs->isEmpty()) {
            return '';
        }

        $ideas = ArticleIdea::query()
            ->where('website_id', $site->id)
            ->where(function ($query) use ($entityPairs) {
                foreach ($entityPairs as [$scope, $name]) {
                    $query->orWhere(function ($q) use ($scope, $name) {
                        $q->where('entity_scope', $scope)->where('entity_name', $name);
                    });
                }
            })
            // ایده‌ای که کاربر از اتوماسیون کنار گذاشته هم نباید با عنوان دیگری دوباره پیشنهاد شود
            ->where(fn ($q) => $q
                ->whereHas('article', fn ($a) => $a->where('status', '!=', Article::STATUS_FAILED))
                ->orWhere('autopilot_excluded', true))
            ->latest('id')
            ->limit(50)
            ->get(['title', 'meta_description', 'entity_name', 'entity_scope']);

        if ($ideas->isEmpty()) {
            return '';
        }

        $lines = ['Article ideas already produced as completed articles, or rejected by the user, for these entities (written or being written; STRICTLY avoid repeating the same topic/angle; do not just reword the title):'];
        foreach ($ideas as $idea) {
            $lines[] = "- [{$idea->entity_scope}|{$idea->entity_name}] {$idea->title}";
        }

        return implode("\n", $lines);
    }

    /** شباهت واژگانی (Jaccard توکن‌های معنادار) که از آن به بعد دو عنوان «همان موضوع» حساب می‌شوند. */
    protected const DUPLICATE_TITLE_SIMILARITY = 0.7;

    /**
     * ایده‌هایی که عنوانشان با یک ایده‌ی موجودِ همین سایت (هر Entity و هر وضعیتی) یا با ایده‌ی دیگری
     * از همین batch تقریباً یکی است حذف می‌کند؛ عنوان یکسان زیر دو Entity هم رقیب هم در گوگل می‌شود.
     * فقط تکرار واضح (همان کلمات با ترتیب/ظاهر دیگر) گرفته می‌شود؛ تکرار معنایی با پرامپت (بخش ۱۲) است.
     */
    protected function filterDuplicateIdeas(Site $site, array $ideas): array
    {
        // ایده‌های قبلی همین سایت + همه‌ی مقاله‌هایی که خود سایت منتشر کرده
        $seen = ArticleIdea::query()
            ->where('website_id', $site->id)
            ->pluck('title')
            ->concat(WordpressArticle::where('site_id', $site->id)->pluck('title'))
            ->map(fn ($title) => $this->titleTokens(html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            ->all();

        $kept = [];

        foreach ($ideas as $idea) {
            $tokens = $this->titleTokens((string) ($idea['title'] ?? ''));

            if ($this->isRoboticTitle((string) ($idea['title'] ?? ''))) {
                Log::info('ArticleIdeaService: robotic idea title dropped', ['site_id' => $site->id, 'title' => $idea['title'] ?? null]);

                continue;
            }

            $isDuplicate = collect($seen)->contains(
                fn ($other) => $this->titleSimilarity($tokens, $other) >= self::DUPLICATE_TITLE_SIMILARITY
            );

            if ($isDuplicate) {
                Log::info('ArticleIdeaService: duplicate idea dropped', [
                    'site_id' => $site->id,
                    'title' => $idea['title'] ?? null,
                ]);

                continue;
            }

            $seen[] = $tokens;
            $kept[] = $idea;
        }

        return $kept;
    }

    /** عبارت‌های کلیشه‌ای که عنوان را ماشینی و غیرانسانی می‌کنند و کسی در گوگل جستجویشان نمی‌کند */
    protected const ROBOTIC_TITLE_PHRASES = ['در دنیای امروز', 'از صفر تا صد', 'همه چیز درباره', 'هر آنچه باید', 'آنچه باید بدانید', 'راهنمای جامع', 'بررسی جامع', 'به طور کامل'];

    protected function isRoboticTitle(string $title): bool
    {
        $normalized = $this->normalizePersianText($title);

        foreach (self::ROBOTIC_TITLE_PHRASES as $phrase) {
            if (str_contains($normalized, $this->normalizePersianText($phrase))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    protected function titleTokens(string $title): array
    {
        $normalized = $this->normalizePersianText($title);
        $tokens = $this->meaningfulTokens($normalized);

        // عنوانی که همه‌اش stopword است: کل متن نرمال‌شده ملاک مقایسه باشد
        return $tokens ?: ($normalized === '' ? [] : [$normalized]);
    }

    /** Jaccard دو مجموعه توکن (۰ تا ۱) */
    protected function titleSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }

    /**
     * خروجی EntityKeywordSuggestionService رو به یک بلوک متنی برای پرامپت AI تبدیل می‌کنه.
     * فرمت هر keyword: [entity_scope|entity_name] base_keyword (relevance: N)
     */
    protected function buildKeywordsBlock(array $keywords): string
    {
        if (empty($keywords)) {
            return '';
        }

        $lines = ['Suggested Search Keywords (از تحقیق کلمات کلیدی واقعی؛ برای هدایت انتخاب عنوان استفاده شود):'];

        foreach ($keywords as $keyword) {
            $scope = $keyword['entity_scope'] ?? '';
            $name = $keyword['entity_name'] ?? '';
            $base = $keyword['base_keyword'] ?? '';
            $relevance = $keyword['total_relevance'] ?? 0;

            if ($base === '') {
                continue;
            }

            $lines[] = "- [{$scope}|{$name}] {$base} (relevance: {$relevance})";
        }

        return implode("\n", $lines);
    }

    /**
     * برای هر idea چک می‌کنه که target_url واقعاً متعلق به همون entity (name + scope) باشه.
     * اگه entity_name اشتباه بود یا url جعلی/نامعتبر بود، لینک null می‌شه ولی idea حذف نمی‌شه.
     */
protected function validateIdeas(array $ideas, WebsiteEntity $entity, array $targets): array
{
    // map: "primary|نام entity" یا "core|نام entity" => [urls مجاز]
    $validLinksByEntity = $this->buildValidLinksMap($entity, $targets);

    $validated = [];

    foreach ($ideas as $idea) {
        $entityName = $idea['entity_name'] ?? null;
        $entityScope = $idea['entity_scope'] ?? null;
        $key = $entityScope.'|'.$entityName;

        $allowedUrls = $validLinksByEntity[$key] ?? null;

        // fallback دفاعی: اگه AI اسم رو درست نوشته ولی scope رو اشتباه داده
        // (مثلاً یک core entity رو primary اعلام کرده)، به‌جای رد کردن idea،
        // scope واقعی رو از روی داده‌ی خودمون پیدا و تصحیح می‌کنیم.
        if ($allowedUrls === null && $entityName !== null) {
            $resolvedScope = $this->resolveActualScope($entityName, $validLinksByEntity);

            if ($resolvedScope !== null) {
                Log::warning('Article idea entity_scope corrected (AI mismatch)', [
                    'entity_name' => $entityName,
                    'ai_scope' => $entityScope,
                    'corrected_scope' => $resolvedScope,
                    'title' => $idea['title'] ?? null,
                ]);

                $entityScope = $resolvedScope;
                $key = $entityScope.'|'.$entityName;
                $allowedUrls = $validLinksByEntity[$key] ?? null;
                $idea['entity_scope'] = $resolvedScope;
            }
        }

        $this->warnIfTooLong($idea);

        if ($allowedUrls === null) {
            // اسم entity هم در primary و هم در core پیدا نشد — واقعاً یک entity ناشناخته‌ست
            Log::warning('Article idea rejected: unknown entity reference', [
                'entity_name' => $entityName,
                'entity_scope' => $entityScope,
                'title' => $idea['title'] ?? null,
            ]);

            continue;
        }

        $targetUrl = $idea['target_url'] ?? null;

        if ($targetUrl === null || ! isset($allowedUrls[$targetUrl])) {
            $idea['target_url'] = null;
            $idea['target_url_title'] = null;

            Log::warning('Article idea target_url invalid or missing, nulled out', [
                'entity_name' => $entityName,
                'given_url' => $targetUrl,
                'title' => $idea['title'] ?? null,
            ]);
        } else {
            $idea['target_url_title'] = $allowedUrls[$targetUrl];
        }

        $validated[] = $idea;
    }

    return $validated;
}

/**
 * وقتی entity_scope داده‌شده توسط AI با کلید map مطابقت نداره، این متد چک می‌کنه
 * آیا همون entity_name با scope دیگری (primary یا core) در داده‌های واقعی وجود داره یا نه.
 * اگه پیدا شد، scope درست رو برمی‌گردونه؛ در غیر این صورت null (یعنی واقعاً entity ناشناخته‌ست).
 */
protected function resolveActualScope(string $entityName, array $validLinksByEntity): ?string
{
    foreach (self::ENTITY_SCOPES as $scope) {
        if (isset($validLinksByEntity[$scope.'|'.$entityName])) {
            return $scope;
        }
    }

    return null;
}

    /**
     * title بیشتر از ~۶۰ کاراکتر و meta_description بیشتر از ~۱۶۰ کاراکتر معمولاً
     * در نتایج گوگل truncate می‌شن. اینجا فقط warning می‌زنیم و idea رو رد نمی‌کنیم؛
     * چون strict json_schema بعضی providerها maxLength رو enforce نمی‌کنن.
     */
    protected function warnIfTooLong(array $idea): void
    {
        $title = $idea['title'] ?? '';
        $meta = $idea['meta_description'] ?? '';

        if (mb_strlen($title) > 45) {
            Log::warning('Article idea title exceeds recommended SEO length', [
                'title' => $title,
                'length' => mb_strlen($title),
            ]);
        }

        if (mb_strlen($meta) > 160) {
            Log::warning('Article idea meta_description exceeds recommended SEO length', [
                'title' => $title,
                'meta_length' => mb_strlen($meta),
            ]);
        }
    }

    /**
     * لیست لینک‌های مجاز رو فقط برای entityهایی که در $targets هستن می‌سازه
     * (primary/core محدود به همون scope مشترک با keyword research).
     * خروجی: ['primary|نام entity' => ['/url1' => 'title1', ...], 'core|نام دیگر' => [...]]
     */
    protected function buildValidLinksMap(WebsiteEntity $entity, array $targets): array
    {
        $map = [];

        $coreEntitiesByName = collect($entity->core_entities ?? [])
            ->filter(fn ($core) => ! empty($core['name']))
            ->keyBy('name');

        foreach ($targets as $target) {
            if ($target['scope'] === 'primary' && ! empty($entity->primary_entity['name'])) {
                $name = $entity->primary_entity['name'];
                $map['primary|'.$name] = $this->extractUrlTitleMap($entity->primary_entity['links'] ?? []);

                continue;
            }

            if ($target['scope'] === 'core') {
                $core = $coreEntitiesByName->get($target['name']);

                if ($core) {
                    $map['core|'.$target['name']] = $this->extractUrlTitleMap($core['links'] ?? []);
                }
            }
        }

        return $map;
    }

    protected function extractUrlTitleMap(array $links): array
    {
        $map = [];

        foreach ($links as $link) {
            if (! empty($link['url'])) {
                $map[$link['url']] = $link['title'] ?? $link['url'];
            }
        }

        return $map;
    }

    /**
     * برای هر idea یک category و ۰ تا ۳ tag از بین taxonomy واقعیِ خودِ سایت انتخاب می‌شود.
     *
     * نکات کلیدیِ این نسخه (هر کدام مستقیماً روی «دقت» اثر دارد):
     *  1) idهای واقعی به‌صورت enum داخل json_schema می‌روند → مدل نمی‌تواند id نامعتبر حدس بزند.
     *  2) مدل call و مدل لاگ‌شده یکی است (TAXONOMY_MODEL) و مدل قوی‌تری برای classification استفاده می‌شود.
     *  3) کل سیگنالِ در دسترس (hook / search_intent / outline / entity_type) به مدل داده می‌شود.
     *  4) اگر taxonomy سایت بزرگ باشد، قبل از AI یک shortlist متنی زده می‌شود (هم سریع‌تر، هم دقیق‌تر).
     *  5) temperature = 0، reason اجباری قبل از انتخاب، و fallback متنی برای ideaهای بدون category.
     *
     * اگر سایت اصلاً category/tag نداشته باشه (وردپرسی نیست یا هنوز taxonomy sync نشده)، هیچ AI
     * callای زده نمی‌شه و idea ها بدون تغییر برمی‌گردن. هر خطای AI هم non-fatal است.
     */
    protected function assignTaxonomy(Site $site, array $ideas): array
    {
        $categories = $this->normalizeTaxonomyList($site->categories ?? []);
        $tags = $this->normalizeTaxonomyList($site->tags ?? []);

        if ($categories->isEmpty() && $tags->isEmpty()) {
            Log::info('ArticleIdeaService: site has no wordpress taxonomy, skipping AI taxonomy call', [
                'site_id' => $site->id ?? null,
            ]);

            return $ideas;
        }

        // فقط وقتی لیست بزرگ‌تر از سقف است shortlist می‌کنیم؛ در غیر این صورت همه‌چیز فرستاده می‌شود.
        $candidateCategories = $this->shortlistTaxonomy($categories, $ideas, self::TAXONOMY_MAX_CATEGORIES_IN_PROMPT);
        $candidateTags = $this->shortlistTaxonomy($tags, $ideas, self::TAXONOMY_MAX_TAGS_IN_PROMPT);

        Log::info('ArticleIdeaService: calling AI for taxonomy assignment', [
            'site_id' => $site->id ?? null,
            'model' => self::TAXONOMY_MODEL,
            'categories_total' => $categories->count(),
            'categories_sent' => $candidateCategories->count(),
            'tags_total' => $tags->count(),
            'tags_sent' => $candidateTags->count(),
            'ideas_count' => count($ideas),
        ]);

        $assignments = $this->requestTaxonomyAssignments($site, $ideas, $candidateCategories, $candidateTags);

        // خطای AI مثل قبل non-fatal است، ولی دیگر «هیچ» برنمی‌گردانیم:
        // حداقل تطابق متنی قطعی را اعمال می‌کنیم تا idea بدون category نماند.
        if ($assignments === null) {
            return $this->fillMissingTaxonomyLexically(
                $this->resetTaxonomyFields($ideas),
                $categories,
                $tags
            );
        }

        // اعتبارسنجی نسبت به کل taxonomy (نه فقط کاندیدها) تا اگر مدل idِ واقعیِ خارج از shortlist داد هم قبول شود.
        $result = $this->applyTaxonomyAssignments($ideas, $assignments, $categories, $tags);
        $result = $this->fillMissingTaxonomyLexically($result, $categories, $tags);

        Log::info('ArticleIdeaService: taxonomy assignment finished', [
            'site_id' => $site->id ?? null,
            'ideas_with_category' => collect($result)->filter(fn ($i) => ! empty($i['category']))->count(),
            'ideas_with_tags' => collect($result)->filter(fn ($i) => ! empty($i['tags']))->count(),
        ]);

        return $result;
    }

    /**
     * AI call با retry. خروجی: آرایه‌ی assignments یا null (یعنی شکست کامل و non-fatal).
     */
    protected function requestTaxonomyAssignments(Site $site, array $ideas, $categories, $tags): ?array
    {
        $payload = [
            'model' => self::TAXONOMY_MODEL,
            'temperature' => 0, // classification قطعی؛ تنوع اینجا فقط نویز است.
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'article_idea_taxonomy',
                    'strict' => true,
                    'schema' => $this->buildTaxonomySchema(count($ideas), $categories, $tags),
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => $this->taxonomySystemPrompt()],
                ['role' => 'user', 'content' => $this->buildTaxonomyUserContent($site, $ideas, $categories, $tags)],
            ],
        ];

        for ($attempt = 1; $attempt <= self::TAXONOMY_MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->callGapgptChat($payload);
            } catch (\Throwable $exception) {
                Log::warning('ArticleIdeaService: taxonomy AI call threw exception', [
                    'site_id' => $site->id ?? null,
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if (! $response || ! $response->successful()) {
                Log::warning('ArticleIdeaService: taxonomy AI request failed', [
                    'site_id' => $site->id ?? null,
                    'attempt' => $attempt,
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                ]);

                continue;
            }

            $message = $response->json('choices.0.message.content');
            $parsed = is_string($message) ? json_decode($message, true) : null;

            if (! is_array($parsed) || ! isset($parsed['assignments']) || ! is_array($parsed['assignments'])) {
                Log::warning('ArticleIdeaService: taxonomy AI response not parsable', [
                    'site_id' => $site->id ?? null,
                    'attempt' => $attempt,
                    'message' => $message,
                ]);

                continue;
            }

            $usage = $response->json('usage');
            if ($usage && $site->user) {
                $this->recordAiTokenUsage(
                    user: $site->user,
                    serviceName: 'ai.article_idea_taxonomy',
                    usage: $usage,
                    model: self::TAXONOMY_MODEL, // دقیقاً همان مدلی که call شد
                );
            }

            return $parsed['assignments'];
        }

        return null;
    }

    /**
     * Schema با enum روی idهای واقعی — مهم‌ترین تغییر این نسخه.
     * ترتیب propertyها عمدی است: مدل اول reason را می‌نویسد و بعد id را انتخاب می‌کند
     * (در strict schema تولید به ترتیب properties انجام می‌شود، پس reason نقش یک استدلال کوتاه را دارد).
     */
    protected function buildTaxonomySchema(int $ideasCount, $categories, $tags): array
    {
        $categoryIds = collect($categories)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->push(0) // 0 = هیچ دسته‌ای واقعاً مناسب نبود
            ->all();

        $tagIds = collect($tags)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $categorySchema = ['type' => 'integer'];
        if (count($categoryIds) > 1) {
            $categorySchema['enum'] = $categoryIds;
        }

        $tagItemSchema = ['type' => 'integer'];
        if (! empty($tagIds)) {
            $tagItemSchema['enum'] = $tagIds;
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
                            'reason' => [
                                'type' => 'string',
                                'description' => 'حداکثر ۱۲ کلمه: چرا این دسته و این تگ‌ها برای موضوع مقاله درست‌اند.',
                            ],
                            'category_id' => $categorySchema,
                            'tag_ids' => [
                                'type' => 'array',
                                'items' => $tagItemSchema,
                                'maxItems' => self::TAXONOMY_MAX_TAGS,
                            ],
                        ],
                        'required' => ['idea_index', 'reason', 'category_id', 'tag_ids'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['assignments'],
            'additionalProperties' => false,
        ];
    }

    protected function taxonomySystemPrompt(): string
    {
        return <<<'PROMPT'
شما یک متخصص دسته‌بندی محتوای وردپرس هستید. برای هر ایده مقاله، دقیقاً یک category_id و صفر تا سه tag_id انتخاب می‌کنید.

روش کار برای هر ایده:
1) اول از روی title و outline تشخیص بده موضوع اصلی مقاله دقیقاً چیست (نه فقط کلمات ظاهری عنوان).
2) بعد reason را در حداکثر ۱۲ کلمه بنویس.
3) سپس category_id و tag_ids را انتخاب کن.

قوانین category:
- فقط از idهای لیست Categories استفاده کن؛ هرگز id جدید نساز.
- نزدیک‌ترین دسته به موضوع اصلی مقاله را انتخاب کن.
- اگر چند دسته مرتبط بودند، خاص‌ترین (دقیق‌ترین) را انتخاب کن، نه عمومی‌ترین.
- مقدار 0 فقط زمانی مجاز است که هیچ دسته‌ای حتی به‌صورت کلی به موضوع مقاله مربوط نباشد. اول جدی تلاش کن نزدیک‌ترین دسته را پیدا کنی؛ 0 آخرین گزینه است.
- دسته را بر اساس موضوع مقاله انتخاب کن، نه بر اساس نام Entity یا یک کلمه‌ی فرعی داخل meta.

قوانین tag:
- فقط از idهای لیست Tags استفاده کن.
- تگ فقط وقتی انتخاب شود که واقعاً موضوع یا زیرموضوع مقاله باشد (در title، outline یا meta به آن پرداخته شده است).
- شباهت اسمی کافی نیست: تگی مثل «قیمت» فقط وقتی انتخاب شود که مقاله واقعاً درباره قیمت/هزینه باشد.
- تگ‌های خیلی عمومی که تقریباً به هر مقاله‌ای می‌خورند انتخاب نشوند.
- اگر هیچ تگ واقعاً مرتبطی نبود، آرایه خالی برگردان. تعداد تگ مهم نیست، دقتش مهم است.
- تگ تکراری نده و بیشتر از ۳ تگ انتخاب نکن.

قوانین خروجی:
- idea_index باید دقیقاً همان اندیسی باشد که در ورودی آمده (شروع از 0) و برای هر ایده دقیقاً یک assignment بده.
- خروجی فقط JSON معتبر مطابق Schema باشد، بدون هیچ متن اضافه.
PROMPT;
    }

    protected function buildTaxonomyUserContent(Site $site, array $ideas, $categories, $tags): string
    {
        $lines = [];

        if (! empty($site->niche) || ! empty($site->domain)) {
            $lines[] = 'Site: '.($site->domain ?? '-').' | Niche: '.($site->niche ?? '-');
            $lines[] = '';
        }

        $lines[] = 'Available Categories (فقط از همین idها):';
        foreach ($categories as $cat) {
            $lines[] = "- id: {$cat['id']} | name: {$cat['name']}";
        }
        if (collect($categories)->isEmpty()) {
            $lines[] = '- (هیچ دسته‌ای موجود نیست؛ category_id = 0)';
        }

        $lines[] = '';
        $lines[] = 'Available Tags (فقط از همین idها):';
        foreach ($tags as $tag) {
            $lines[] = "- id: {$tag['id']} | name: {$tag['name']}";
        }
        if (collect($tags)->isEmpty()) {
            $lines[] = '- (هیچ تگی موجود نیست؛ tag_ids = [])';
        }

        $lines[] = '';
        $lines[] = 'Article Ideas:';

        foreach (array_values($ideas) as $index => $idea) {
            $outline = collect($idea['outline'] ?? [])->filter()->implode(' | ');

            $lines[] = "- idea_index: {$index}";
            $lines[] = '  title: '.($idea['title'] ?? '');
            $lines[] = '  entity: '.($idea['entity_name'] ?? '').' ('.($idea['entity_type'] ?? '-').')'
                .' | intent: '.($idea['search_intent'] ?? '-');
            $lines[] = '  meta: '.($idea['meta_description'] ?? '');

            if (! empty($idea['hook'])) {
                $lines[] = '  hook: '.$idea['hook'];
            }

            if ($outline !== '') {
                $lines[] = '  outline: '.$outline;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * اعمال خروجی AI روی ideaها + اعتبارسنجی سخت‌گیرانه‌ی idها.
     * idهای نامعتبر دیگر بی‌صدا drop نمی‌شوند؛ لاگ می‌شوند تا اگر باز هم اتفاق افتاد قابل ردیابی باشد.
     */
    protected function applyTaxonomyAssignments(array $ideas, array $assignments, $categories, $tags): array
    {
        $categoriesById = collect($categories)->keyBy(fn ($c) => (int) $c['id']);
        $tagsById = collect($tags)->keyBy(fn ($t) => (int) $t['id']);

        // مدل ممکن است idea_index را به‌صورت string برگرداند → cast اجباری.
        $byIndex = collect($assignments)->keyBy(fn ($a) => (int) ($a['idea_index'] ?? -1));

        foreach ($ideas as $index => &$idea) {
            $idea['category'] = null;
            $idea['tags'] = [];

            $assignment = $byIndex->get((int) $index);

            if (! $assignment) {
                Log::warning('ArticleIdeaService: no taxonomy assignment returned for idea', [
                    'idea_index' => $index,
                    'title' => $idea['title'] ?? null,
                ]);

                continue;
            }

            $categoryId = (int) ($assignment['category_id'] ?? 0);

            if ($categoryId > 0 && $categoriesById->has($categoryId)) {
                $cat = $categoriesById->get($categoryId);
                $idea['category'] = [
                    'id' => (int) $cat['id'],
                    'name' => $cat['name'],
                    'slug' => $cat['slug'] ?? null,
                ];
            } elseif ($categoryId > 0) {
                Log::warning('ArticleIdeaService: AI returned unknown category_id', [
                    'idea_index' => $index,
                    'category_id' => $categoryId,
                    'title' => $idea['title'] ?? null,
                    'reason' => $assignment['reason'] ?? null,
                ]);
            }

            $rawTagIds = collect($assignment['tag_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();

            $unknownTagIds = $rawTagIds->reject(fn ($id) => $tagsById->has($id))->values();
            if ($unknownTagIds->isNotEmpty()) {
                Log::warning('ArticleIdeaService: AI returned unknown tag_ids', [
                    'idea_index' => $index,
                    'tag_ids' => $unknownTagIds->all(),
                    'title' => $idea['title'] ?? null,
                ]);
            }

            $idea['tags'] = $rawTagIds
                ->filter(fn ($id) => $tagsById->has($id))
                ->take(self::TAXONOMY_MAX_TAGS)
                ->map(fn ($id) => $tagsById->get($id))
                ->map(fn ($t) => [
                    'id' => (int) $t['id'],
                    'name' => $t['name'],
                    'slug' => $t['slug'] ?? null,
                ])
                ->values()
                ->all();

            if (! empty($assignment['reason'])) {
                Log::info('ArticleIdeaService: taxonomy decision', [
                    'idea_index' => $index,
                    'title' => $idea['title'] ?? null,
                    'category' => $idea['category']['name'] ?? null,
                    'tags' => collect($idea['tags'])->pluck('name')->all(),
                    'reason' => $assignment['reason'],
                ]);
            }
        }
        unset($idea);

        return $ideas;
    }

    /**
     * اگر AI برای یک idea هیچ category نداده (یا کل call شکست خورده)، با تطابق متنی
     * نزدیک‌ترین دسته را انتخاب می‌کنیم — فقط وقتی تطابق واقعاً قوی باشد.
     * هدف: idea بدون category نماند، ولی دسته‌ی بی‌ربط هم چسبانده نشود.
     */
    protected function fillMissingTaxonomyLexically(array $ideas, $categories, $tags): array
    {
        $categories = collect($categories);
        $tags = collect($tags);

        foreach ($ideas as $index => &$idea) {
            $text = $this->ideaSearchText($idea);
            $tokens = $this->meaningfulTokens($text);

            if (empty($idea['category']) && $categories->isNotEmpty()) {
                $best = $this->bestTaxonomyMatch($categories, $text, $tokens);

                if ($best && $best['score'] >= self::TAXONOMY_FALLBACK_MIN_CATEGORY_SCORE) {
                    $idea['category'] = [
                        'id' => (int) $best['item']['id'],
                        'name' => $best['item']['name'],
                        'slug' => $best['item']['slug'] ?? null,
                    ];

                    Log::info('ArticleIdeaService: category filled by lexical fallback', [
                        'idea_index' => $index,
                        'title' => $idea['title'] ?? null,
                        'category' => $best['item']['name'],
                        'score' => $best['score'],
                    ]);
                }
            }

            if (empty($idea['tags']) && $tags->isNotEmpty()) {
                $best = $this->bestTaxonomyMatch($tags, $text, $tokens);

                if ($best && $best['score'] >= self::TAXONOMY_FALLBACK_MIN_TAG_SCORE) {
                    $idea['tags'] = [[
                        'id' => (int) $best['item']['id'],
                        'name' => $best['item']['name'],
                        'slug' => $best['item']['slug'] ?? null,
                    ]];

                    Log::info('ArticleIdeaService: tag filled by lexical fallback', [
                        'idea_index' => $index,
                        'title' => $idea['title'] ?? null,
                        'tag' => $best['item']['name'],
                        'score' => $best['score'],
                    ]);
                }
            }
        }
        unset($idea);

        return $ideas;
    }

    protected function resetTaxonomyFields(array $ideas): array
    {
        foreach ($ideas as &$idea) {
            $idea['category'] = null;
            $idea['tags'] = [];
        }
        unset($idea);

        return $ideas;
    }

    /**
     * نرمال‌سازی لیست category/tag سایت:
     * - decode شدن entityهای HTML (وردپرس اسم‌ها را &amp; / &#8211; برمی‌گرداند)
     * - cast شدن id به int و حذف موارد ناقص/تکراری
     * - نگه‌داشتن فرم نرمال‌شده‌ی نام برای امتیازدهی تطابق
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,name:string,slug:?string,normalized:string}>
     */
    protected function normalizeTaxonomyList($items)
    {
        return collect($items)
            ->filter(fn ($i) => is_array($i) && ! empty($i['id']) && ! empty($i['name']))
            ->map(function ($i) {
                $name = trim(html_entity_decode((string) $i['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return [
                    'id' => (int) $i['id'],
                    'name' => $name,
                    'slug' => $i['slug'] ?? null,
                    'normalized' => $this->normalizePersianText($name),
                ];
            })
            ->filter(fn ($i) => $i['id'] > 0 && $i['name'] !== '')
            ->unique('id')
            ->values();
    }

    /**
     * وقتی سایت صدها tag دارد، فرستادن همه‌شان هم کند است هم توجه مدل را رقیق می‌کند.
     * اینجا اجتماعِ «بهترین کاندیدهای هر idea» ساخته می‌شود و تا سقف مشخص پر می‌شود.
     * اگر لیست از سقف کوچک‌تر باشد، دست‌نخورده برمی‌گردد.
     */
    protected function shortlistTaxonomy($items, array $ideas, int $maxTotal)
    {
        $items = collect($items);

        if ($items->count() <= $maxTotal) {
            return $items;
        }

        $scores = [];

        foreach ($ideas as $idea) {
            $text = $this->ideaSearchText($idea);
            $tokens = $this->meaningfulTokens($text);

            $items
                ->map(fn ($item) => ['item' => $item, 'score' => $this->scoreTaxonomyItem($item, $text, $tokens)])
                ->filter(fn ($row) => $row['score'] > 0)
                ->sortByDesc('score')
                ->take(self::TAXONOMY_PER_IDEA_CANDIDATES)
                ->each(function ($row) use (&$scores) {
                    $id = (int) $row['item']['id'];
                    $scores[$id] = max($scores[$id] ?? 0, $row['score']);
                });
        }

        $selected = $items
            ->filter(fn ($item) => isset($scores[(int) $item['id']]))
            ->sortByDesc(fn ($item) => $scores[(int) $item['id']])
            ->take($maxTotal)
            ->values();

        // اگر تطابق متنی کم بود، تا سقف با بقیه پر می‌کنیم تا گزینه‌ی مدل محدود نشود.
        if ($selected->count() < $maxTotal) {
            $selectedIds = $selected->pluck('id')->map(fn ($id) => (int) $id)->all();

            $selected = $selected->concat(
                $items
                    ->reject(fn ($item) => in_array((int) $item['id'], $selectedIds, true))
                    ->take($maxTotal - $selected->count())
            )->values();
        }

        return $selected;
    }

    /**
     * @return array{item: array, score: int}|null
     */
    protected function bestTaxonomyMatch($items, string $text, array $tokens): ?array
    {
        $best = collect($items)
            ->map(fn ($item) => ['item' => $item, 'score' => $this->scoreTaxonomyItem($item, $text, $tokens)])
            ->sortByDesc('score')
            ->first();

        return ($best && $best['score'] > 0) ? $best : null;
    }

    /**
     * امتیاز تطابق یک category/tag با متن idea:
     *  +10 اگر کل نام تاکسونومی عیناً داخل متن آمده باشد
     *  +3  به‌ازای هر توکن معنادار مشترک
     *  +1  برای توکن‌های بلندی که به‌صورت جزئی داخل متن دیده می‌شوند (ریشه‌ی مشترک)
     */
    protected function scoreTaxonomyItem(array $item, string $ideaText, array $ideaTokens): int
    {
        $normalized = $item['normalized'] ?? '';

        if ($normalized === '' || $ideaText === '') {
            return 0;
        }

        $score = 0;

        if (mb_strpos($ideaText, $normalized) !== false) {
            $score += 10;
        }

        foreach ($this->meaningfulTokens($normalized) as $token) {
            if (in_array($token, $ideaTokens, true)) {
                $score += 3;

                continue;
            }

            if (mb_strlen($token) >= 4 && mb_strpos($ideaText, $token) !== false) {
                $score += 1;
            }
        }

        return $score;
    }

    protected function ideaSearchText(array $idea): string
    {
        $parts = [
            $idea['title'] ?? '',
            $idea['meta_description'] ?? '',
            $idea['hook'] ?? '',
            $idea['entity_name'] ?? '',
            $idea['entity_type'] ?? '',
            collect($idea['outline'] ?? [])->filter()->implode(' '),
        ];

        return $this->normalizePersianText(implode(' ', $parts));
    }

    /**
     * نرمال‌سازی متن فارسی: حذف نیم‌فاصله/اعراب، یکسان‌سازی ی/ک عربی، حذف نشانه‌گذاری.
     */
    protected function normalizePersianText(string $text): string
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
    protected function meaningfulTokens(string $normalizedText): array
    {
        if ($normalizedText === '') {
            return [];
        }

        return collect(explode(' ', $normalizedText))
            ->filter(fn ($token) => mb_strlen($token) >= 3 && ! in_array($token, self::TAXONOMY_STOPWORDS, true))
            ->unique()
            ->values()
            ->all();
    }

protected function systemPrompt(int $ideasCount, string $language = ContentLanguage::DEFAULT): string
{
    $languageRules = ContentLanguage::isPersian($language) ? <<<'RULES'
- عنوان، hook، meta_description و outline باید تماماً به فارسی روان و محاوره‌ایِ رایج نوشته شوند، نه فارسی رسمی/کتابی سنگین.
- از قاطی‌کردن بی‌دلیل کلمات انگلیسی وسط جمله‌ی فارسی خودداری کن (مثلاً به‌جای «ساختار Topic Cluster» بنویس «ساختار خوشه‌ای محتوا» یا «خوشه محتوایی»؛ به‌جای «آپدیت» بنویس «به‌روزرسانی» وقتی معادل فارسی رایج و کاملاً جاافتاده است).
- کلمه‌ی انگلیسی را فقط زمانی به‌کار ببر که واقعاً هیچ معادل فارسیِ رایج و طبیعی ندارد یا خودِ آن کلمه در زبان روزمره‌ی فارسی جاافتاده است (مثل «سئو»، «گوگل»). در این حالت هم ساده‌ترین و کوتاه‌ترین شکل ممکن آن را با حروف فارسی (نه لاتین) بنویس، نه اصطلاح تخصصی و پیچیده.
- هرگز کلمات انگلیسی را با حروف لاتین وسط عنوان یا متن فارسی ننویس (مثلاً "Topic Cluster" یا "SEO" با حروف انگلیسی ممنوع است؛ اگر لازم بود بنویس "سئو").
RULES : <<<'RULES'
- عنوان، hook، meta_description و outline باید تماماً به زبان سایت (فارسی) روان، طبیعی و رایج نوشته شوند؛ دقیقاً همان‌طور که کاربران بومی آن زبان در گوگل جست‌وجو می‌کنند.
- از قاطی‌کردن کلمات زبان دیگر وسط جمله خودداری کن، مگر نام برند یا اصطلاحی که در همان زبان رایج است.
RULES;

    $template = <<<'PROMPT'

    شما یک استراتژیست محتوا و متخصص سئو هستید. وظیفه شما تولید دقیقاً {IDEAS_COUNT} ایده مقاله وبلاگ بر اساس Entityهای سایت است. همیشه به فارسی پاسخ دهید.

هدف اصلی:
تولید ایده‌های مقاله‌ای که بتوانند برای کاربران واقعی ارزش ایجاد کنند، شانس رتبه گرفتن در گوگل داشته باشند و به‌صورت طبیعی به صفحات سایت لینک داخلی بدهند.

قوانین:
1. ارتباط با Entity
- هر ایده فقط به یکی از Entityهای داده‌شده مربوط باشد.
- entity_name را دقیقاً همان‌طور که دریافت کرده‌ای استفاده کن.
- Entity جدید ایجاد نکن.
- entity_scope باید دقیقاً مطابق همان بخشی باشد که entity از آن گرفته شده:
  اگر entity زیر عنوان "Primary Entity:" آمده → entity_scope = "primary"
  اگر entity زیر عنوان "Core Entity:" آمده → entity_scope = "core"
  هرگز به یک Core Entity مقدار "primary" نده، حتی اگر آن را مهم‌تر یا اصلی‌تر تشخیص دادی.
- موضوع مقاله باید یکی از مشکلات، سوالات، دغدغه‌ها، سناریوهای واقعی، مقایسه‌ها یا تصمیم‌های کاربران مرتبط با همان Entity را پوشش دهد؛ نه صرفاً خود Entity.
2. جلوگیری از Keyword Cannibalization
- اگر Query بیشتر مناسب صفحه سرویس، محصول، دسته‌بندی یا لندینگ است، همان موضوع را به مقاله تبدیل نکن.
- در این حالت زاویه‌ای آموزشی، تحلیلی، مقایسه‌ای یا مسئله‌محور انتخاب کن که بتواند به همان صفحه لینک داخلی بدهد.

3. لینک داخلی
- target_url و target_url_title فقط از لینک‌های همان Entity انتخاب شوند.
- URL جدید یا ساختگی تولید نکن.
- مقاله باید به‌صورت طبیعی بتواند به آن لینک داخلی ارجاع دهد.

4. قوانین Title (سخت‌گیرانه)
Title باید:
- کوتاه باشد: حداکثر ۴۵ کاراکتر و ۸ کلمه (فاصله‌ها هم حساب می‌شوند). این سقف اکید است؛ هرچه کوتاه‌تر و نزدیک‌تر به عبارت جست‌وجو، بهتر.
- شبیه عبارتی باشد که کاربر واقعی در گوگل تایپ می‌کند (Search Query)، نه جمله‌ی توضیحی، محاوره‌ای یا سوال گفت‌وگویی.
- در عین حال انسانی و طبیعی باشد: وقتی بلند خوانده می‌شود، مثل عنوانی باشد که یک نویسنده‌ی واقعی فارسی‌زبان نوشته، با حرف‌های ربط درست (برای، با، در، و)؛ نه ردیف کلمه‌های کلیدی پشت سر هم.
  درست: «آموزش تولید محتوا با هوش مصنوعی برای مبتدی‌ها». غلط (ماشینی): «آموزش تولید محتوا هوش مصنوعی مبتدی».
  درست: «آموزش تولید محتوا با هوش مصنوعی»، «تولید محتوا با هوش مصنوعی چیست»، «بهترین ابزار تولید محتوا با هوش مصنوعی»، «مقایسه محتوای انسانی و هوش مصنوعی»، «چک لیست تولید محتوا با هوش مصنوعی».
  غلط: «تولید محتوا با هوش مصنوعی چه وقتی خوب جواب می‌دهد؟»، «چطور خروجی هوش مصنوعی را انسانی‌تر کنیم و رتبه بگیریم».
- عبارت کلیدی اصلی را عیناً و در ابتدای عنوان داشته باشد و فقط یک یا دو کلمه‌ی نیت جست‌وجو به آن اضافه شود (مثل آموزش، چیست، بهترین، قیمت، هزینه، مقایسه، روش، مراحل، ابزار، نمونه، چک لیست، اشتباهات رایج، برای مبتدیان).
- اگر Suggested Search Keywords وجود دارد، عنوان را از روی یکی از همان عبارت‌ها (یا نزدیک‌ترین شکل رایج آن) بساز؛ عبارتی که کسی جست‌وجو نمی‌کند ننویس.
- بدون علامت سوال، ویرگول، دونقطه، خط تیره یا جمله‌ی دوم.
- شبیه صفحه خدمات، محصول یا دسته‌بندی نباشد (مثلاً فقط «تولید محتوا با هوش مصنوعی» بدون کلمه‌ی نیت، عنوان صفحه‌ی خدمت است نه مقاله).
- عدد فقط وقتی که واقعاً در Outline پوشش داده می‌شود؛ سال یا آمار ساختگی ننویس.
- کلیک‌بِیت و اغراق («تضمینی»، «باورنکردنی»، «فقط در ۵ دقیقه») ممنوع است؛ عنوان کلی مثل «همه چیز درباره X» هم ممنوع است.

4-ب. موضوع انسانی (اکید)
- موضوع یک نیاز ملموس و واقعی است که یک آدم مشخص در زندگی یا کارش دارد (یک سؤال، یک تصمیم، یک مشکل)، نه یک موضوع کلی و انشایی.
- این الگوهای ماشینی ممنوع است: «X در دنیای امروز»، «X از صفر تا صد»، «همه چیز درباره X»، «هر آنچه باید درباره X بدانید»، «راهنمای جامع X»، «بررسی جامع X»، «آینده‌ی X»، «تحول X».
- قبل از نوشتن هر ایده از خودت بپرس: «آیا یک آدم واقعی دقیقاً همین را در گوگل می‌پرسد و آیا یک نویسنده‌ی انسانی این عنوان را می‌نوشت؟» اگر نه، عوضش کن.

4-الف. زبان و کلمات (اکید)
{LANGUAGE_RULES}

5. تنوع (اکید، بین همه‌ی {IDEAS_COUNT} ایده‌ی همین دسته)
- هر ایده یک نیت جست‌وجوی متفاوت را هدف بگیرد (آموزش، چیستی، بهترین/ابزار، مقایسه، قیمت/هزینه، مراحل، اشتباهات رایج، نمونه، چک لیست، ...)؛ هیچ دو عنوانی در یک batch کلمه‌ی نیت یکسان نداشته باشند.
- تنوع از نیت جست‌وجو می‌آید، نه از ساختن جمله‌های متفاوت؛ عنوان‌ها همگی کوتاه و شبیه عبارت جست‌وجو بمانند.

6. Search Intent
برای هر ایده ابتدا Intent را تحلیل کن و سپس Title را بساز.
search_intent فقط یکی از این مقادیر باشد:
informational
commercial
transactional
navigational

7. Meta Description
- حداکثر 160 کاراکتر (این سقف اکید است).
- طبیعی، محاوره‌ای و مناسب CTR.
- خلاصه مقاله باشد، نه تبلیغ.

8. Hook
- کوتاه، جذاب و بیان‌کننده مشکل یا نیاز کاربر؛ به فارسی ساده.

9. Outline
- شامل 4 تا 6 زیرعنوان.
- دقیقاً وعده Title را پوشش دهد.
- از زیرعنوان‌های عمومی و تکراری استفاده نکن.
- ساختار زیرعنوان‌ها هم باید بین ایده‌های مختلف همین batch متفاوت باشد (نه یک الگوی کپی‌شده برای همه).

10. Priority
عددی بین 0 تا 100 باشد.
Primary Entity معمولاً اولویت بیشتری دارد، اما امتیازها را منطقی توزیع کن.

11. Suggested Search Keywords
اگر Suggested Search Keywords وجود داشت:
- Intent آن‌ها را تحلیل کن.
- فقط Keyword را تکرار نکن.
- اگر Keyword مناسب مقاله نیست یا بیشتر مناسب صفحه سرویس است، از آن استفاده نکن.

12. جلوگیری از تکرار با مقالات قبلی و با بقیه‌ی ایده‌های همین batch
اگر بخش "The site owner's own most recent articles" وجود داشت، آن مقاله‌ها همین الان روی سایت هستند؛ هیچ ایده‌ای که همان سؤال را جواب بدهد یا بازنویسی عنوان آن‌ها باشد نساز. موضوع تازه می‌تواند کنار آن‌ها باشد (سؤال دنباله‌دار یا جزئی‌تر)، نه تکرارشان.
اگر بخش "Article ideas already produced as completed articles" وجود داشت:
- هیچ ایده‌ای که موضوع، Search Intent یا زاویه‌اش مشابه یکی از آن‌ها باشد تولید نکن.
- صرفاً تغییر جزئی در عبارت‌بندی Title کافی نیست؛ باید واقعاً زاویه یا سوال متفاوتی نسبت به همه‌ی آن مقالات پوشش داده شود.
- اگر تمام زوایای بدیهی مرتبط با یک Entity قبلاً پوشش داده شده، به‌سراغ زیرموضوعات جزئی‌تر، سناریوهای خاص‌تر یا سوالات دنباله‌دار (follow-up) برو؛ اما هرگز موضوع تکراری تولید نکن.
- دو عنوان که با کلمات متفاوت به یک Search Intent و یک سوال واحد جواب می‌دهند (مثلاً «زمان کاشت گوجه» و «بهترین فصل کاشت گوجه») تکراری حساب می‌شوند.
- علاوه بر مقالات قبلی، ایده‌های همین batch هم نباید موضوعاً هم‌پوشانی داشته باشند؛ هر {IDEAS_COUNT} ایده باید نیاز جستجوی کاملاً متفاوتی را هدف بگیرند، نه چند زاویه‌ی نزدیک از یک موضوع مشترک.

13. بررسی نهایی (قبل از خروجی، برای تک‌تک ایده‌ها و برای کل batch)
✓ آیا موضوع مشکل یا نیاز واقعی کاربر را حل می‌کند؟
✓ آیا این موضوع باید مقاله باشد، نه صفحه سرویس یا محصول؟
✓ آیا Title زیر ۴۵ کاراکتر، بدون علامت سوال و دقیقاً شبیه عبارتی است که کاربر در گوگل تایپ می‌کند، با عبارت کلیدی اصلی در ابتدای آن؟
✓ آیا کاربر با دیدن این عنوان در نتایج گوگل دقیقاً می‌فهمد چه جوابی می‌گیرد و رویش کلیک می‌کند؟
✓ آیا زبان عنوان و متن فارسیِ روان است و کلمه‌ی غیرضروری از زبان دیگر ندارد؟
✓ آیا نیت جست‌وجوی این عنوان با عنوان‌های دیگر همین batch فرق دارد؟
✓ آیا با سایر ایده‌ها (و با مقالات قبلاً تولیدشده و مقاله‌های آخر خود سایت) از نظر موضوع هم متفاوت است؟
✓ آیا موضوع و عنوان انسانی و طبیعی است، نه کلیشه‌ای و ماشینی؟
✓ آیا لینک داخلی انتخاب‌شده منطقی است؟
✓ آیا Search Intent درست انتخاب شده است؟

در صورت منفی بودن هر مورد، ایده را بازنویسی کن.

خروجی فقط JSON معتبر مطابق Schema داده‌شده باشد و هیچ متن دیگری خارج از JSON ننویس.
PROMPT;

    return ContentLanguage::localize(
        str_replace(['{IDEAS_COUNT}', '{LANGUAGE_RULES}'], [(string) $ideasCount, rtrim($languageRules)], $template),
        $language,
    );
}

    protected function buildSchema(int $ideasCount): array
    {
        $ideaSchema = [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'maxLength' => 60,
                    'description' => 'حداکثر ۶۰ کاراکتر، به زبان سایت، روان، جمله‌ی ساده‌ی تک‌بخشی.',
                ],
                'meta_description' => [
                    'type' => 'string',
                    'maxLength' => 160,
                    'description' => 'حداکثر ۱۶۰ کاراکتر، به زبان سایت، روان.',
                ],
                'hook' => ['type' => 'string'],
                'entity_name' => ['type' => 'string'],
                'entity_scope' => ['type' => 'string', 'enum' => self::ENTITY_SCOPES],
                'entity_type' => ['type' => 'string'],
                'search_intent' => [
                    'type' => 'string',
                    'enum' => ['informational', 'commercial', 'transactional', 'navigational'],
                ],
                'target_url' => ['type' => 'string'],
                'target_url_title' => ['type' => 'string'],
                'priority' => ['type' => 'integer'],
                'outline' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'title', 'meta_description', 'hook', 'entity_name',
                'entity_scope', 'entity_type', 'search_intent', 'target_url', 'target_url_title',
                'priority', 'outline',
            ],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'ideas' => [
                    'type' => 'array',
                    'items' => $ideaSchema,
                    'minItems' => $ideasCount,
                    'maxItems' => $ideasCount,
                ],
            ],
            'required' => ['ideas'],
            'additionalProperties' => false,
        ];
    }

    /**
     * فقط entityهایی که در $targets هستن (primary + N تا core اول، هماهنگ با keyword research)
     * رو به AI نشون می‌ده — نه همه‌ی core_entities رو، تا AI برای entityای که اصلاً قرار نیست
     * توش محتوا تولید بشه ایده نسازه.
     */
    protected function buildEntitiesBlock(WebsiteEntity $entity, array $targets): string
    {
        $coreEntitiesByName = collect($entity->core_entities ?? [])
            ->filter(fn ($core) => ! empty($core['name']))
            ->keyBy('name');

        $lines = [];

        foreach ($targets as $target) {
            if ($target['scope'] === 'primary') {
                $lines[] = 'Primary Entity:';
                $lines[] = json_encode($entity->primary_entity, JSON_UNESCAPED_UNICODE);
            } else {
                $core = $coreEntitiesByName->get($target['name']);

                if ($core) {
                    $lines[] = "\nCore Entity:";
                    $lines[] = json_encode($core, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return implode("\n", $lines);
    }
}
