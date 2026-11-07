<?php

namespace App\Services\AI;

use App\Models\Article;
use App\Models\ArticleSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArticleActionExecutor
{
    public function __construct(
        private readonly ArticleWriterService $writer,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @param  string  $model  مدلی که کاربر برای این پیامِ چت انتخاب کرده.
     * @return array<int, array<string, mixed>>
     */
    public function execute(Article $article, array $actions, string $model): array
    {
        $results = [];

        foreach ($actions as $action) {
            $type = $action['type'] ?? null;

            try {
                $results[] = match ($type) {
                    'UPDATE_SECTION' => $this->handleUpdateSection($article, $action, $model),
                    'ADD_SECTION' => $this->handleAddSection($article, $action, $model),
                    'DELETE_SECTION' => $this->handleDeleteSection($article, $action),
                    'MOVE_SECTION' => $this->handleMoveSection($article, $action),
                    'CHANGE_TITLE' => $this->handleChangeTitle($article, $action),
                    'CHANGE_META' => $this->handleChangeMeta($article, $action),
                    'REWRITE_ARTICLE' => $this->handleRewriteArticle(
                        $article,
                        'این بخش را با حفظ معنا بازنویسی کن.',
                        $model,
                    ),
                    'CHANGE_TONE' => ! empty($action['section_id'])
                        ? $this->handleUpdateSection($article, [
                            'section_id' => $action['section_id'],
                            'instruction' => 'لحن این بخش را به این‌صورت تغییر بده: '.($action['tone'] ?? ''),
                        ], $model)
                        : $this->handleRewriteArticle(
                            $article,
                            'لحن این بخش را به این‌صورت تغییر بده: '.($action['tone'] ?? ''),
                            $model,
                        ),
                    'SHORTEN_OR_EXPAND_ARTICLE' => $this->handleShortenOrExpand($article, $action, $model),
                    default => ['type' => $type, 'ok' => false, 'error' => 'Unknown action type.'],
                };
            } catch (\Throwable $e) {
                Log::error('ai.article_executor.action_failed', [
                    'article_id' => $article->id,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);

                $results[] = ['type' => $type, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function handleUpdateSection(Article $article, array $action, string $model): array
    {
        $section = ArticleSection::where('article_id', $article->id)->findOrFail($action['section_id']);

        $result = $this->writer->chatEdit(
            article: $article,
            targetType: 'section',
            targetLabel: $section->heading,
            currentValue: $section->content,
            history: [],
            userMessage: (string) $action['instruction'],
            model: $model,
        );

        if (! empty($result['updated_content'])) {
            $section->update(['content' => $result['updated_content']]);
        }

        return [
            'type' => 'UPDATE_SECTION',
            'ok' => true,
            'section_id' => $section->id,
            'reply' => $result['reply'],
        ];
    }

    private function handleAddSection(Article $article, array $action, string $model): array
    {
        $insertPosition = $this->resolveAddSectionPosition($article, $action);

        DB::transaction(function () use ($article, $insertPosition) {
            ArticleSection::where('article_id', $article->id)
                ->where('position', '>=', $insertPosition)
                ->increment('position');
        });

        $section = ArticleSection::create([
            'article_id' => $article->id,
            'type' => 'section',
            'heading' => $action['section_title'] ?? $action['new_title'] ?? 'بخش جدید',
            'subheadings' => [],
            'key_points' => ! empty($action['instruction']) ? [$action['instruction']] : [],
            'target_words' => 400,
            'position' => $insertPosition,
            'content' => null,
        ]);

        $content = $this->writer->generateSection($section, $model);

        $section->update([
            'content' => $content,
            'status' => ArticleSection::STATUS_GENERATED,
        ]);

        return ['type' => 'ADD_SECTION', 'ok' => true, 'section_id' => $section->id];
    }

    private function resolveAddSectionPosition(Article $article, array $action): int
    {
        $sections = $article->sections()->orderBy('position')->get(['id', 'position', 'type']);

        $boundary = $sections->first(fn ($s) => in_array($s->type, ['faq', 'conclusion'], true));
        $boundaryPosition = $boundary?->position ?? (($sections->max('position') ?? -1) + 1);

        $targetPosition = $boundaryPosition;

        if (! empty($action['after_section_id'])) {
            $afterSection = $sections->firstWhere('id', $action['after_section_id']);
            if ($afterSection) {
                $targetPosition = min($afterSection->position + 1, $boundaryPosition);
            }
        }

        return $targetPosition;
    }

    private function handleDeleteSection(Article $article, array $action): array
    {
        $section = ArticleSection::where('article_id', $article->id)->findOrFail($action['section_id']);
        $sectionId = $section->id;
        $section->delete();

        return ['type' => 'DELETE_SECTION', 'ok' => true, 'section_id' => $sectionId];
    }

    private function handleMoveSection(Article $article, array $action): array
    {
        $section = ArticleSection::where('article_id', $article->id)->findOrFail($action['section_id']);
        $section->update(['position' => $action['new_position']]);

        return ['type' => 'MOVE_SECTION', 'ok' => true, 'section_id' => $section->id];
    }

    private function handleChangeTitle(Article $article, array $action): array
    {
        $article->update(['title' => $action['new_title']]);

        return ['type' => 'CHANGE_TITLE', 'ok' => true];
    }

    private function handleChangeMeta(Article $article, array $action): array
    {
        $payload = array_filter([
            'meta_title' => $action['meta_title'] ?? null,
            'meta_description' => $action['meta_description'] ?? null,
        ]);

        $article->update($payload);

        return ['type' => 'CHANGE_META', 'ok' => true];
    }

    private function handleRewriteArticle(Article $article, string $instruction, string $model): array
    {
        $sections = $article->sections()->orderBy('position')->get();
        $updated = [];

        foreach ($sections as $section) {
            $result = $this->writer->chatEdit(
                article: $article,
                targetType: 'section',
                targetLabel: $section->heading,
                currentValue: $section->content,
                history: [],
                userMessage: $instruction,
                model: $model,
            );

            if (! empty($result['updated_content'])) {
                $section->update(['content' => $result['updated_content']]);
                $updated[] = $section->id;
            }
        }

        return ['type' => 'REWRITE_ARTICLE', 'ok' => true, 'updated_section_ids' => $updated];
    }

    /**
     * اگر Planner یک section_id مشخص کرده باشد، فقط همان section اجرا می‌شود
     * (نه کل مقاله) — این جلوی مصرف اضافیِ توکن/زمان وقتی کاربر فقط یک بخش
     * را خواسته را می‌گیرد.
     */
    private function handleShortenOrExpand(Article $article, array $action, string $model): array
    {
        $direction = ($action['direction'] ?? '') === 'shorten' ? 'کوتاه‌تر' : 'مفصل‌تر';
        $instruction = "این بخش را {$direction} کن. ".($action['instruction'] ?? '');

        if (! empty($action['section_id'])) {
            return $this->handleUpdateSection($article, [
                'section_id' => $action['section_id'],
                'instruction' => $instruction,
            ], $model);
        }

        return $this->handleRewriteArticle($article, $instruction, $model);
    }
}
