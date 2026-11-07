<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleRefresh extends Model
{
    use HasPublicToken;
    public const STATUS_QUEUED = 'queued';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_READY = 'ready';
    public const STATUS_APPLYING = 'applying';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REVERTED = 'reverted';
    public const STATUS_DISCARDED = 'discarded';
    public const STATUS_FAILED = 'failed';

    /** وضعیت‌هایی که هنوز کاری روی مقاله در جریان است؛ برای یک مقاله فقط یکی از این‌ها مجاز است. */
    public const OPEN_STATUSES = [self::STATUS_QUEUED, self::STATUS_GENERATING, self::STATUS_READY, self::STATUS_APPLYING];

    public const REASON_NEAR_FIRST_PAGE = 'near_first_page';
    public const REASON_DECLINING = 'declining';
    public const REASON_LOW_CTR = 'low_ctr';
    public const REASON_MANUAL = 'manual';

    /** مقاله‌ای که با یوتیکس ساخته شده، یا پست قدیمی خود سایت (آپدیت مقاله‌های گذشته). */
    public const SOURCE_ARTICLE = 'article';
    public const SOURCE_WP_POST = 'wp_post';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_AGENT = 'agent';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'queries' => 'array',
            'before' => 'array',
            'after' => 'array',
            'credit_used' => 'float',
            'applied_at' => 'datetime',
            'stage_started_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function wordpressArticle(): BelongsTo
    {
        return $this->belongsTo(WordpressArticle::class);
    }

    /** عنوان برای نمایش، فارغ از اینکه مقاله‌ی یوتیکس است یا پست قدیمی سایت. */
    public function title(): ?string
    {
        return $this->source === self::SOURCE_WP_POST ? $this->wordpressArticle?->title : $this->article?->title;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
