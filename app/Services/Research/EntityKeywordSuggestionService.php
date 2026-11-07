<?php

namespace App\Services\Research;

use App\Exceptions\JustSerpNoActiveKeysException;
use App\Models\Site;
use App\Services\AI\Concerns\UsesGapgptApiKey;
use App\Services\AI\RecordsTokenUsage;
use App\Services\JustSerpApiClient;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EntityKeywordSuggestionService
{
    use RecordsTokenUsage, UsesGapgptApiKey;

    public function __construct(
        protected KwscraperServicePy $kwscraperService,
        protected JustSerpApiClient $justSerpApiClient
    ) {
    }

    /**
     * اجرای فرایند اصلی: دریافت پیشنهادات خام و نرمال‌سازی آن‌ها با AI
     */
    public function generate(Site $site, ?string $selectedName = null, ?string $selectedScope = null): array
    {
        Log::info("=== Start Job for Site ID: {$site->id} ({$site->domain}) ===");

        $entities = $this->buildTargetEntities($site, $selectedName, $selectedScope);

        Log::info("Entities count for site {$site->id}: " . count($entities));

        if (empty($entities)) {
            Log::warning("No entities found for site_id={$site->id}. Aborting JustSerpAPI call.");
            return [];
        }

        $language = ContentLanguage::forSite($site);

        $rawSuggestionsByEntity = [];

        foreach ($entities as $entity) {
            $scope = is_object($entity) ? ($entity->scope ?? 'core') : ($entity['scope'] ?? 'core');
            $name = is_object($entity) ? ($entity->name ?? $entity->title ?? null) : ($entity['name'] ?? $entity['title'] ?? null);

            if (! $name) {
                continue;
            }

            $key = "{$scope}|{$name}";

            Log::info("JustSerpAPI: Requesting query -> [{$name}]");

            $suggestions = $this->fetchAutocomplete($name, $language);
            $rawSuggestionsByEntity[$key] = $suggestions;
        }

        Log::info("=== Starting AI Normalization (GapGPT) ===");

        return $this->normalizeWithAI($rawSuggestionsByEntity, $site);
    }

    /**
     * استخراج انتیتی‌ها از رابطه entity() موجود روی مدل Site
     */
    public function buildTargetEntities(Site $site, ?string $selectedName = null, ?string $selectedScope = null): array
    {
        if (! $site->relationLoaded('entity')) {
            $site->load('entity');
        }

        $websiteEntity = $site->ensureEntity();

        if (! $websiteEntity) {
            Log::warning("WebsiteEntity relation is null for site_id={$site->id}");
            return [];
        }

        if (! empty($websiteEntity->entities)) {
            $entities = is_array($websiteEntity->entities)
                ? $websiteEntity->entities
                : json_decode($websiteEntity->entities, true) ?? [];

            return $this->filterSelectedEntity($entities, $selectedName, $selectedScope);
        }

        if (! empty($websiteEntity->core_entities)) {
            $entities = is_array($websiteEntity->core_entities)
                ? $websiteEntity->core_entities
                : json_decode($websiteEntity->core_entities, true) ?? [];

            if (! empty($websiteEntity->primary_entity['name'])) {
                array_unshift($entities, array_merge($websiteEntity->primary_entity, ['scope' => 'primary']));
            }

            return $this->filterSelectedEntity($entities, $selectedName, $selectedScope);
        }

        if (! empty($websiteEntity->target_entities)) {
            $entities = is_array($websiteEntity->target_entities)
                ? $websiteEntity->target_entities
                : json_decode($websiteEntity->target_entities, true) ?? [];

            return $this->filterSelectedEntity($entities, $selectedName, $selectedScope);
        }

        $aiData = $websiteEntity->ai_response ?? $site->ai_response ?? [];
        if (! empty($aiData['entities'])) {
            return $this->filterSelectedEntity($aiData['entities'], $selectedName, $selectedScope);
        }
        if (! empty($aiData['core_entities'])) {
            return $this->filterSelectedEntity($aiData['core_entities'], $selectedName, $selectedScope);
        }

        return [];
    }

    protected function filterSelectedEntity(array $entities, ?string $selectedName, ?string $selectedScope): array
    {
        if ($selectedName === null || trim($selectedName) === '') {
            return $entities;
        }

        $selectedName = mb_strtolower(trim($selectedName));

        $filtered = array_values(array_filter($entities, static function (array $entity) use ($selectedName, $selectedScope): bool {
            $name = mb_strtolower(trim((string) ($entity['name'] ?? $entity['title'] ?? '')));
            $scope = $entity['scope'] ?? null;

            return $name === $selectedName && ($selectedScope === null || $scope === null || $scope === $selectedScope);
        }));

        if ($selectedScope !== null) {
            $filtered = array_map(static function (array $entity) use ($selectedScope): array {
                $entity['scope'] = $entity['scope'] ?? $selectedScope;
                return $entity;
            }, $filtered);
        }

        return $filtered;
    }

    /**
     * دریافت پیشنهادات Google Autocomplete برای یک کوئری.
     *
     * اولویت اول: اسکریپت پایتون (Kwscraper) — محلی، بدون هزینه‌ی API.
     * اگر پایتون به هر دلیلی (exception، exit code غیر صفر، JSON نامعتبر،
     * یا خروجی کاملاً خالی) جواب سالم نداد → fallback کامل به JustSerpAPI
     * (از طریق JustSerpApiClient مرکزی) با همان منطق قبلی (چرخش بین کلیدها،
     * غیرفعال‌سازی کلید در صورت اتمام اعتبار).
     */
    protected function fetchAutocomplete(string $query, string $language = ContentLanguage::DEFAULT): array
    {
        $pythonResult = $this->fetchAutocompleteViaPython($query, $language);

        if ($pythonResult !== null && count($pythonResult) > 0) {
            Log::info('[Kwscraper] ' . count($pythonResult) . " کلمه برای [{$query}] از پایتون دریافت شد.");
            return $pythonResult;
        }

        Log::warning("[Kwscraper] پاسخ OK/کامل دریافت نشد برای [{$query}] — سوییچ به JustSerpAPI.");

        return $this->fetchAutocompleteViaJustSerp($query, $language);
    }

    /**
     * تلاش برای دریافت پیشنهادات از طریق اسکریپت پایتون.
     * خروجی را دقیقاً به شکل [ ['value' => ..., 'relevance' => ...], ... ]
     * برمی‌گرداند تا با prompt سازی AI سازگار بماند.
     *
     * @return array<int, array{value: string, relevance: int}>|null  null یعنی شکست کامل
     */
    protected function fetchAutocompleteViaPython(string $query, string $language = ContentLanguage::DEFAULT): ?array
    {
        try {
            $keywords = $this->kwscraperService->suggest($query, [
                'lang'    => $language,
                'country' => ContentLanguage::country($language),
            ]);
        } catch (Throwable $e) {
            Log::error("[Kwscraper] Exception for [{$query}]: " . $e->getMessage());
            return null;
        }

        if (! is_array($keywords) || empty($keywords)) {
            return null;
        }

        $total = count($keywords);

        return array_values(array_map(
            static fn (string $kw, int $i): array => ['value' => $kw, 'relevance' => $total - $i],
            $keywords,
            array_keys($keywords)
        ));
    }

    /**
     * دریافت پیشنهادات Google Autocomplete از JustSerpAPI (fallback)
     * از طریق سرویس مرکزی JustSerpApiClient — دیگر هیچ فراخوانی مستقیم
     * Http:: یا دسترسی به جدول justserp_keys در این کلاس وجود ندارد؛
     * تمام آن منطق در JustSerpApiClient::autocomplete() متمرکز شده است.
     */
    protected function fetchAutocompleteViaJustSerp(string $query, string $language = ContentLanguage::DEFAULT): array
    {
        try {
            return $this->justSerpApiClient->autocomplete($query, [
                'language' => $language,
                'country' => ContentLanguage::country($language),
            ]);
        } catch (JustSerpNoActiveKeysException $e) {
            // این خطای پیکربندی است (هیچ کلید فعالی در سیستم نیست)، نه
            // شکست موقتی یک کوئری خاص. مطابق رفتار قبلی (قبل از تجمیع در
            // JustSerpApiClient)، این حالت باید کل job را متوقف کند، نه
            // اینکه به‌شکل خاموش تبدیل به [] شود و AI را با ورودی خالی
            // صدا بزنیم.
            Log::error("JustSerpAPI error");
            throw $e;
        } catch (Throwable $e) {
            // همه‌ی کلیدهای فعال برای این کوئری خاص امتحان و رد شدند
            // (یا خطای شبکه‌ای موقتی رخ داد) — همان‌طور که در پیاده‌سازی
            // قبلی بود، این حالت silent است و فقط [] برمی‌گردد تا سایر
            // انتیتی‌ها/کوئری‌ها پردازش خودشان را ادامه دهند.
            Log::error("JustSerpAPI Exception for [{$query}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * ارسال داده‌های خام به OpenAI جهت ادغام لوکیشن‌ها و حذف کلمات بی‌ربط
     */
    protected function normalizeWithAI(array $rawSuggestionsByEntity, Site $site): array
    {
        $model = 'gpt-5-nano';

        $userContent = $this->buildUserContent($rawSuggestionsByEntity, $site);
        $systemPrompt = ContentLanguage::localize($this->systemPrompt($site), ContentLanguage::forSite($site));

        Log::info("AI Request Specs:", [
            'model' => $model,
        ]);

        try {
            $response = $this->callGapgptChat([
                'model' => $model,
                // 'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ], 120);

            Log::info("GapGPT/OpenAI RAW Response:", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (! $response->successful()) {
                Log::error("GapGPT Error Status: {$response->status()}");
                throw new RuntimeException('The AI failed to respond.');
            }

            $content = $response->json('choices.0.message.content');

            $usage = $response->json('usage');
            if ($usage && $site->user) {
                $this->recordAiTokenUsage(
                    user: $site->user,
                    serviceName: 'ai.keyword_normalization',
                    usage: $usage,
                    model: $model,
                );
            }

            $data = json_decode($content, true);

            return $this->keepSiteLanguage((array) ($data['keywords'] ?? []), ContentLanguage::forSite($site));

        } catch (Throwable $e) {
            Log::error("GapGPT Exception: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * کلمه‌ی کلیدی باید به زبان خود سایت باشد: سایت فارسی/عربی فقط عبارت با حروف همان خط،
     * سایت انگلیسی (و بقیه) فقط عبارت بدون حروف فارسی/عربی. عبارت‌های زبان دیگر کنار گذاشته می‌شوند.
     */
    protected function keepSiteLanguage(array $keywords, string $language): array
    {
        $arabicScript = in_array($language, ['fa', 'ar', 'ur', 'ps', 'ku'], true);

        return array_values(array_filter($keywords, function ($keyword) use ($arabicScript, $language) {
            $text = is_array($keyword) ? (string) ($keyword['base_keyword'] ?? '') : '';
            $matches = $text !== '' && (bool) preg_match('/\p{Arabic}/u', $text) === $arabicScript;

            if (! $matches) {
                Log::info('EntityKeywordSuggestion: keyword dropped (not in site language)', [
                    'language' => $language,
                    'keyword' => $text,
                ]);
            }

            return $matches;
        }));
    }

    protected function buildUserContent(array $rawSuggestionsByEntity, Site $site): string
    {
        $summary = $site->entity?->summary ?? $site->summary ?? 'اطلاعات خلاصه‌ای ثبت نشده است.';

        $lines = [];
        $lines[] = "دامنه وب‌سایت: {$site->domain}";
        $lines[] = "خلاصه موضوع و فعالیت اصلی وب‌سایت (Summary): {$summary}";
        $lines[] = "---";

        foreach ($rawSuggestionsByEntity as $key => $suggestions) {
            [$scope, $name] = explode('|', $key, 2);

            $lines[] = "### Entity (scope={$scope}, name={$name})";

            if (empty($suggestions)) {
                $lines[] = '(هیچ پیشنهادی برنگشت)';
                $lines[] = '';
                continue;
            }

            foreach ($suggestions as $s) {
                $value = $s['value'] ?? '';
                $relevance = $s['relevance'] ?? 0;
                $lines[] = "- {$value} (relevance: {$relevance})";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function systemPrompt(Site $site): string
    {
        $domain = $site->domain ?? 'سایت مشتری';
        $summary = $site->entity?->summary ?? $site->summary ?? 'خلاصه مشخص نشده است.';

        return <<<PROMPT
شما یک متخصص حرفه‌ای سئو هستید. وظیفه شما پاکسازی خروجی Google Autocomplete برای وب‌سایت زیر است:
- دامنه: {$domain}
- حوزه فعالیت (Summary): {$summary}

قوانین پالایش:
۱. تمام نام‌های شهر/استان/منطقه را ۱۰۰٪ در یک عبارت پایه (بدون شهر) ادغام کن و total_relevance آن‌ها را جمع بزن.
۲. عبارات مربوط به حل جدول، دیکشنری/ترجمه انگلیسی، استخدام و عکس/فیلم را کاملاً حذف کن.
۳. برندهای رقیب و پلتفرم‌ها (دیوار، شیپور و...) را حذف کن.
۴. زبان: base_keyword و source_variants فقط به زبان فارسی (زبان خود سایت) باشند؛ عبارت‌هایی که به زبان دیگری (مثلاً انگلیسی با حروف لاتین) هستند را حذف کن و ترجمه نکن. entity_name را دقیقاً همان‌طور که در ورودی آمده بنویس.

مهم: پاسخ شما **اکیداً و فقط** باید یک JSON معتبر با ساختار زیر باشد:
{
  "keywords": [
    {
      "entity_scope": "core",
      "entity_name": "پرستار سالمند",
      "base_keyword": "پرستار سالمند در منزل",
      "total_relevance": 5000,
      "source_variants": ["پرستار سالمند در منزل", "پرستار سالمند در منزل تهران"]
    }
  ]
}
PROMPT;
    }
}
