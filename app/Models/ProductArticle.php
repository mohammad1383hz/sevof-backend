<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductArticle extends Model
{
        use HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CREATING_BRIEF = 'creating_brief';
    public const STATUS_CREATING_ASSIGNMENTS = 'creating_assignments';
    public const STATUS_GENERATING_SECTIONS = 'generating_sections';
    public const STATUS_GENERATING_META = 'generating_meta';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PAUSED_CREDIT = 'paused_credit';
public const IMAGE_STATUS_PENDING = 'pending';
public const IMAGE_STATUS_GENERATING = 'generating';
public const IMAGE_STATUS_GENERATED = 'generated';
public const IMAGE_STATUS_FAILED = 'failed';
    protected $guarded = [];

    protected $casts = [
        'categories' => 'array',
        'product_ids' => 'array',
        'brief' => 'array',
        'raw_assignments_result' => 'array',
        'faq_schema' => 'array',
        'product_schema' => 'array',
        'completed_at' => 'datetime',
        'meta_generated_at' => 'datetime',
    ];

    public static function createFromIdea(ProductSeoIdea $idea): self
    {
        return static::create([
            'site_id' => $idea->site_id,
            'product_seo_idea_id' => $idea->id,
            'title' => $idea->title,
            'content_style' => $idea->content_style,
            'categories' => $idea->categories,
            'product_ids' => $idea->product_ids,
            'status' => self::STATUS_PENDING,
        ]);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function idea(): BelongsTo
    {
        return $this->belongsTo(ProductSeoIdea::class, 'product_seo_idea_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductArticleAssignment::class, 'product_article_id')->orderBy('position');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ProductArticleSection::class, 'product_article_id')->orderBy('position');
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
        ]);
    }

    public function markPausedForCredit(?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_PAUSED_CREDIT,
            'error_message' => $message ?: 'Not enough plan credit to continue generating the article.',
        ]);
    }
}
