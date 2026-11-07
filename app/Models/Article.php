<?php
// app/Models/Article.php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use App\Enums\ImageStyle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    use HasPublicToken;
    public const STATUS_PENDING = 'pending';
    public const STATUS_CREATING_BRIEF = 'creating_brief';
    public const STATUS_GENERATING_OUTLINE = 'generating_outline';
    public const STATUS_GENERATING_SECTIONS = 'generating_sections';
    public const STATUS_GENERATING_META = 'generating_meta';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const IMAGE_STATUS_PENDING = 'pending';
    public const IMAGE_STATUS_GENERATING = 'generating';
    public const IMAGE_STATUS_GENERATED = 'generated';
    public const IMAGE_STATUS_FAILED = 'failed';

    // وضعیتِ بنرِ تبلیغاتی — دقیقاً هم‌الگو با IMAGE_STATUS_* بالا
    public const BANNER_STATUS_PENDING = 'pending';
    public const BANNER_STATUS_GENERATING = 'generating';
    public const BANNER_STATUS_GENERATED = 'generated';
    public const BANNER_STATUS_FAILED = 'failed';

    public const STATUS_PAUSED_CREDIT = 'paused_credit';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AGENT = 'agent';

    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'brief' => 'array',
            'image_style' => ImageStyle::class,
 'categories' => 'array',
    'tags' => 'array',
        'image_gallery' => 'array',
        'outline' => 'array',
                'wp_tag_ids'  => 'array',
        'wp_new_tags' => 'array',

        'seo_review' => 'array',
        'faq_schema' => 'array',
        'completed_at' => 'datetime',
    ];

    public function markPausedForCredit(?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_PAUSED_CREDIT,
            'error_message' => $message ?? 'Article generation paused because you ran out of credit. Renew or upgrade your plan to continue from here.',
        ]);
    }

    public function idea(): BelongsTo
    {
        return $this->belongsTo(ArticleIdea::class, 'article_idea_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'website_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ArticleSection::class)->orderBy('position');
    }

    public function chatMessages()
    {
        return $this->hasMany(ArticleChatMessage::class);
    }

    // یک مقاله می‌تونه چند بنر داشته باشه (کاربر بعداً از بین بنرهای
    // ساخته‌شده‌ی سایتش هرکدوم رو خواست به این مقاله attach می‌کنه)
    public function banners(): BelongsToMany
    {
        return $this->belongsToMany(Banner::class, 'article_banner')->withTimestamps();
    }

    // آخرین بنری که به این مقاله attach شده — همینه که باید موقع
    // publish/update به وردپرس فرستاده بشه (نه همه‌ی بنرها).
    public function latestBanner(): ?Banner
    {
        return $this->banners()
            ->orderBy('article_banner.created_at', 'desc')
            ->first();
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
        ]);
    }
}
