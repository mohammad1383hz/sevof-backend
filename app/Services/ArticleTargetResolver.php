<?php

namespace App\Services;

use App\Models\Article;
use InvalidArgumentException;

/**
 * لایه‌ی مشترک برای خوندن و نوشتنِ محتوای هر بخش قابل‌انتخاب مقاله
 * (چه از مسیر ویرایش دستی، چه از مسیر چت با AI).
 */
class ArticleTargetResolver
{
    public const TYPES = [
        'section',
        'faq',
        'title',
        'meta_title',
        'meta_description',
        'excerpt',
        'general',
    ];

    /** انواعی که برای مشخص کردنشون target_id لازمه */
    public const TYPES_REQUIRING_ID = ['section', 'faq'];

    /**
     * مقدار فعلیِ target رو برمی‌گردونه.
     * خروجی: ['label' => string|null, 'value' => string|array]
     */
    public function resolve(Article $article, string $type, ?int $id): array
    {
        return match ($type) {
            'section' => $this->resolveSection($article, $id),
            'faq' => $this->resolveFaq($article, $id),
            'title' => ['label' => 'Article title', 'value' => (string) $article->title],
            'meta_title' => ['label' => 'Meta title (SEO title)', 'value' => (string) $article->meta_title],
            'meta_description' => ['label' => 'Meta description', 'value' => (string) $article->meta_description],
            'excerpt' => ['label' => 'Article summary', 'value' => (string) $article->excerpt],
            'general' => ['label' => 'Full article text', 'value' => (string) $article->content],
            default => throw new InvalidArgumentException("Invalid section type: {$type}"),
        };
    }

    /**
     * مقدار جدید رو روی target اعمال و در دیتابیس ذخیره می‌کنه.
     * خروجی: ['label' => string|null, 'value' => string|array] (مقدار به‌روزشده)
     */
    public function apply(Article $article, string $type, ?int $id, mixed $newValue): array
    {
        return match ($type) {
            'section' => $this->applySection($article, $id, $newValue),
            'faq' => $this->applyFaq($article, $id, $newValue),
            'title' => $this->applySimpleField($article, 'title', $newValue),
            'meta_title' => $this->applySimpleField($article, 'meta_title', $newValue),
            'meta_description' => $this->applySimpleField($article, 'meta_description', $newValue),
            'excerpt' => $this->applySimpleField($article, 'excerpt', $newValue),
            'general' => $this->applySimpleField($article, 'content', $newValue),
            default => throw new InvalidArgumentException("Invalid section type: {$type}"),
        };
    }

    private function resolveSection(Article $article, ?int $id): array
    {
        $section = $article->sections()->find($id);

        if (! $section) {
            throw new InvalidArgumentException('Section not found.');
        }

        return ['label' => $section->heading, 'value' => (string) $section->content];
    }

    private function applySection(Article $article, ?int $id, mixed $newValue): array
    {
        $section = $article->sections()->find($id);

        if (! $section) {
            throw new InvalidArgumentException('Section not found.');
        }

        $section->update(['content' => (string) $newValue]);

        return ['label' => $section->heading, 'value' => $section->content];
    }

    private function resolveFaq(Article $article, ?int $id): array
    {
        $faq = $article->faq ?? [];

        if ($id === null || ! isset($faq[$id])) {
            throw new InvalidArgumentException('FAQ item not found.');
        }

        return ['label' => $faq[$id]['question'] ?? null, 'value' => $faq[$id]];
    }

    private function applyFaq(Article $article, ?int $id, mixed $newValue): array
    {
        $faq = $article->faq ?? [];

        if ($id === null || ! isset($faq[$id])) {
            throw new InvalidArgumentException('FAQ item not found.');
        }

        $faq[$id] = [
            'question' => $newValue['question'] ?? $faq[$id]['question'],
            'answer' => $newValue['answer'] ?? $faq[$id]['answer'],
        ];

        $article->update(['faq' => $faq]);

        return ['label' => $faq[$id]['question'], 'value' => $faq[$id]];
    }

    private function applySimpleField(Article $article, string $field, mixed $newValue): array
    {
        $article->update([$field => (string) $newValue]);

        return ['label' => null, 'value' => $article->{$field}];
    }
}
