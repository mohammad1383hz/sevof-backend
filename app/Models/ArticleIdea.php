<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ArticleIdea extends Model
{
    use HasPublicToken;
    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'outline' => 'array',
        'ai_raw_response' => 'array',
        'score_breakdown' => 'array',
        'serp_data' => 'array',
         'category' => 'array',
    'tags' => 'array',
        'priority' => 'integer',
        'seo_score' => 'integer',
        'scored_at' => 'datetime',
        'autopilot_excluded' => 'boolean',
    ];

    /** ایده‌هایی که اتوماسیون (Agent) مجاز است از آن‌ها مقاله بسازد. */
    public function scopeAvailableForAutopilot($query)
    {
        return $query->where('autopilot_excluded', false)
            ->where('status', '!=', 'rejected')
            ->whereDoesntHave('article', fn ($q) => $q->whereNotIn('status', [Article::STATUS_FAILED]));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'website_id');
    }

    /** مقاله‌ی تولیدشده برای این ایده (اگر وجود داشته باشد). */
    public function article(): HasOne
    {
        return $this->hasOne(Article::class, 'article_idea_id');
    }
    public function relatedLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(ArticleIdeaRelatedLink::class);
}
}
