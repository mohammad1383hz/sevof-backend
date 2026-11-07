<?php

namespace App\Services\AI;

use App\Models\Site;
use App\Services\AI\Concerns\UsesGapgptApiKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EntitySelectionService
{
    use RecordsTokenUsage, UsesGapgptApiKey;

    public const MODEL = 'gpt-5-nano';
    // این resolver داخل request وب اجرا می‌شود؛ نباید از max_execution_time پیش‌فرض PHP عبور کند.
    protected const MODEL_TIMEOUT_SECONDS = 8;

    /**
     * Resolve the entity for an opportunity request.
     *
     * An explicit selection is always preferred and does not call the model.
     * When the user only writes a natural-language request, the small model
     * chooses one of the real entities or returns needs_entity.
     */
    public function resolve(
        Site $site,
        string $prompt,
        ?string $requestedName = null,
        ?string $requestedScope = null,
    ): array {
        $entities = $this->entitiesForSite($site);

        if ($entities === []) {
            throw new RuntimeException('No valid entities are recorded for this site.');
        }

        if ($requestedName !== null && trim($requestedName) !== '') {
            $selected = $this->findEntity($entities, trim($requestedName), $requestedScope);

            if ($selected === null) {
                return $this->needsEntity($entities, 'Entity انتخاب‌شده در فهرست Entityهای این سایت وجود ندارد.');
            }

            return [
                'status' => 'selected',
                'entity' => $selected,
                'entities' => $entities,
            ];
        }

        // An exact entity name in the prompt is deterministic and cheaper than
        // an extra model call. The model remains the fallback for natural language.
        $mentioned = $this->findExactMentionedEntity($entities, $prompt);
        if ($mentioned !== null) {
            return [
                'status' => 'selected',
                'entity' => $mentioned,
                'entities' => $entities,
            ];
        }

        try {
            $response = $this->callGapgptChat([
                'model' => self::MODEL,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'entity_selection',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'matched' => ['type' => 'boolean'],
                            'entity_name' => ['type' => ['string', 'null']],
                            'entity_scope' => ['type' => ['string', 'null'], 'enum' => ['primary', 'core', null]],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => ['matched', 'entity_name', 'entity_scope', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'user_request' => $prompt,
                        'available_entities' => $entities,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            ], self::MODEL_TIMEOUT_SECONDS);
        } catch (\Throwable $exception) {
            Log::warning('EntitySelectionService: model unavailable', [
                'site_id' => $site->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->needsEntity($entities, 'برای تولید دقیق فرصت محتوایی، لطفاً یکی از Entityهای زیر را مشخص کنید.');
        }

        if (! $response->successful()) {
            Log::warning('EntitySelectionService: model request failed', [
                'site_id' => $site->id,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            // A failed inexpensive classifier must not invent an entity.
            return $this->needsEntity($entities, 'برای تولید دقیق فرصت محتوایی، لطفاً یکی از Entityهای زیر را مشخص کنید.');
        }

        $message = $response->json('choices.0.message.content');
        $parsed = is_string($message) ? json_decode($message, true) : null;

        if ($site->user && is_array($response->json('usage'))) {
            $this->recordAiTokenUsage(
                user: $site->user,
                serviceName: 'ai.entity_selection',
                usage: $response->json('usage'),
                model: self::MODEL,
            );
        }

        if (! is_array($parsed) || ! ($parsed['matched'] ?? false)) {
            return $this->needsEntity($entities, 'برای تولید دقیق فرصت محتوایی، لطفاً مشخص کنید کدام Entity مدنظر شماست.');
        }

        $selected = $this->findEntity(
            $entities,
            (string) ($parsed['entity_name'] ?? ''),
            $parsed['entity_scope'] ?? null,
        );

        if ($selected === null || (float) ($parsed['confidence'] ?? 0) < 0.70) {
            return $this->needsEntity($entities, 'برای جلوگیری از انتخاب اشتباه، لطفاً یکی از Entityهای زیر را صریحاً انتخاب کنید.');
        }

        return [
            'status' => 'selected',
            'entity' => $selected,
            'entities' => $entities,
        ];
    }

    /**
     * انتخاب entity برای مسیر اتوماسیون (بدون هیچ دخالت کاربر یا prompt).
     * به‌جای انتخاب رندوم، entityای که "دیرتر از همه" براش ایده تولید شده
     * (یا اصلاً هیچ‌وقت) رو برمی‌گردونه تا در طول زمان پوشش همه‌ی entityهای
     * سایت عادلانه باشه، نه وابسته به شانس.
     *
     * این متد هیچ AI صدا نمی‌زنه؛ فقط از تاریخچه‌ی جدول article_ideas می‌خونه،
     * پس صدا زدنش هزینه‌ی محسوسی نداره.
     */
    public function pickAutomationEntity(Site $site): array
    {
        $entities = $this->entitiesForSite($site);

        if ($entities === []) {
            throw new RuntimeException('No valid entities are recorded for this site.');
        }

        $lastGenerated = DB::table('article_ideas')
            ->where('website_id', $site->id)
            ->selectRaw('entity_scope, entity_name, MAX(created_at) as last_at')
            ->groupBy('entity_scope', 'entity_name')
            ->get()
            ->keyBy(fn ($row) => $row->entity_scope.'|'.$row->entity_name);

        $never = [];
        $seen = [];

        foreach ($entities as $entity) {
            $key = $entity['scope'].'|'.$entity['name'];
            $row = $lastGenerated->get($key);

            if ($row === null) {
                $never[] = $entity;
            } else {
                $seen[] = ['entity' => $entity, 'last_at' => $row->last_at];
            }
        }

        // entityهایی که تا حالا هیچ‌وقت ایده نگرفتن، اولویت مطلق دارن؛ بین خودشون
        // shuffle می‌کنیم که حتی این حالت اولیه هم به یک ترتیب ثابت/قابل‌پیش‌بینی نباشه.
        if (! empty($never)) {
            shuffle($never);

            return $never[0];
        }

        usort($seen, fn ($a, $b) => strcmp($a['last_at'], $b['last_at']));

        return $seen[0]['entity'];
    }

    /** @return array<int, array{name:string, scope:string, type:string|null, description:string, links:array}> */
    public function entitiesForSite(Site $site): array
    {
        $websiteEntity = $site->relationLoaded('entity') ? $site->entity : $site->entity()->first();
        if (! $websiteEntity) {
            return [];
        }

        $entities = [];
        $primary = is_array($websiteEntity->primary_entity) ? $websiteEntity->primary_entity : null;
        if (! empty($primary['name'])) {
            $entities[] = $this->normalizeEntity($primary, 'primary');
        }

        $cores = is_array($websiteEntity->core_entities) ? $websiteEntity->core_entities : [];
        foreach ($cores as $core) {
            if (! empty($core['name'])) {
                $entities[] = $this->normalizeEntity($core, 'core');
            }
        }

        return $entities;
    }

    protected function normalizeEntity(array $entity, string $scope): array
    {
        return [
            'name' => (string) $entity['name'],
            'scope' => $scope,
            'type' => isset($entity['type']) ? (string) $entity['type'] : null,
            'description' => isset($entity['description']) ? (string) $entity['description'] : '',
            'links' => is_array($entity['links'] ?? null) ? $entity['links'] : [],
        ];
    }

    protected function findEntity(array $entities, string $name, ?string $scope = null): ?array
    {
        $name = mb_strtolower(trim($name));
        $matches = array_values(array_filter($entities, function (array $entity) use ($name, $scope): bool {
            return mb_strtolower($entity['name']) === $name
                && ($scope === null || $entity['scope'] === $scope);
        }));

        return count($matches) === 1 ? $matches[0] : null;
    }

    protected function findExactMentionedEntity(array $entities, string $prompt): ?array
    {
        $matches = array_values(array_filter($entities, fn (array $entity): bool =>
            $entity['name'] !== '' && mb_stripos($prompt, $entity['name']) !== false
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    protected function needsEntity(array $entities, string $message): array
    {
        return [
            'status' => 'needs_entity',
            'message' => $message,
            'entities' => $entities,
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
تو یک دستیار انتخاب Entity برای سرویس استراتژی محتوا هستی. entity_name را عیناً (بدون ترجمه یا تغییر) همان‌طور که در available_entities آمده برگردان.
فقط از available_entities انتخاب کن و هرگز Entity جدید نساز.
اگر متن کاربر به‌طور روشن فقط به یک Entity اشاره می‌کند، matched=true و نام و scope دقیق همان Entity را برگردان.
اگر متن مبهم است، چند Entity ممکن است یا هیچ Entity مشخصی ندارد، matched=false و فیلدهای entity_name و entity_scope را null برگردان.
confidence عددی بین 0 و 1 است و فقط میزان اطمینان به همین انتخاب را نشان می‌دهد.
خروجی فقط JSON مطابق schema باشد.
PROMPT;
    }
}
