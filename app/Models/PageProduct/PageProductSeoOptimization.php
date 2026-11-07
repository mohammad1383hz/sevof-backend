<?php

namespace App\Models\PageProduct;

use App\Models\Concerns\HasPublicToken;
use App\Models\Site;
use App\Models\SiteProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageProductSeoOptimization extends Model
{
    use HasPublicToken;
    public const STATUS_PENDING = 'pending';
    public const STATUS_ANALYZING = 'analyzing';
    public const STATUS_READY = 'ready';          // آماده‌ی پیش‌نمایش و تأیید
    public const STATUS_SKIPPED = 'skipped';      // نقصی نداشت
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    /**
     * فیلدهایی که قابل تولید/تأیید هستند (ترتیب = ترتیب نمایش در UI).
     * باید با FIELD_ORDER فرانت و کلیدهای payload سرویس انتشار یکی باشد.
     */
    public const FIELDS = [
        'short_description',
        'description',
        'category_link',
        'attributes',
        'tags',
        'faq',
        'image_alts',
        'seo_title',
        'meta_description',
        'focus_keyword',
    ];

    protected $guarded = [];

    protected $casts = [
        'needs_search' => 'boolean',
        'missing_fields' => 'array',
        'search_data' => 'array',
        'result' => 'array',
        'approved_fields' => 'array',
        'published_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SiteProduct::class, 'site_product_id');
    }
}
