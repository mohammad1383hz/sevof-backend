<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleIdeaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->token,
            'website_id' => $this->website_id,
            'title' => $this->title,
            'meta_description' => $this->meta_description,
            'hook' => $this->hook,
            'entity' => [
                'name' => $this->entity_name,
                'scope' => $this->entity_scope,
                'type' => $this->entity_type,
            ],
            'internal_link' => [
                'url' => $this->target_url,
                'title' => $this->target_url_title,
            ],
            // لینک‌های داخلی که RelatedArticleLinkService به‌صورت local (بدون AI) از
            // روی مقالات وردپرسی سایت پیدا کرده. whenLoaded یعنی اگر کنترلر
            // ->with('relatedLinks') را فراموش کند، به‌جای N+1 کوئری فقط [] برمی‌گردد.
            'related_links' => $this->whenLoaded('relatedLinks', fn () => $this->relatedLinks
                ->map(fn ($link) => [
                    'url' => $link->url,
                    'title' => $link->title,
                    'match_score' => $link->match_score,
                ])
                ->values()),
            // category/tags توسط ArticleIdeaService::assignTaxonomy از بین taxonomy واقعیِ
            // خودِ سایت (وردپرس) انتخاب شده‌اند؛ اگر سایت taxonomy نداشته یا AI مورد مناسبی
            // پیدا نکرده باشد، category می‌تواند null و tags آرایه‌ی خالی باشد.
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'outline' => $this->outline,
            'priority' => $this->priority,
            'batch' => $this->batch,
            'seo_score' => $this->seo_score,
            'score_breakdown' => $this->score_breakdown,
            'scored_at' => $this->scored_at,
            'scoring_error' => $this->scoring_error,
            'is_scored' => $this->seo_score !== null,

            'status' => $this->status,
            'autopilot_excluded' => (bool) $this->autopilot_excluded,
            'has_article' => $this->status === 'used',
            // شناسه‌ی مقاله‌ی واقعاً تولیدشده — از رابطه‌ی eager-loaded خوانده
            // می‌شود، نه یک کوئری اضافه. whenLoaded یعنی اگر کنترلر ->with('article')
            // را فراموش کند، این فیلد به‌جای N+1 کوئری، فقط null برمی‌گردد.
            'article_id' => $this->whenLoaded('article', fn () => $this->article?->token),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
