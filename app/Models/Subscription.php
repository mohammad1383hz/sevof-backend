<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * --- ویژگی جدید: صف پلن بعدی ---
     * اشتراکی که کاربر از قبل (وقتی هنوز پلن فعلی‌ش تموم نشده) خریده،
     * ولی هنوز فعال نشده. starts_at / ends_at هنوز null هستن و فقط
     * وقتی پلن فعلی کاربر (به هر دلیلی: تاریخ یا کردیت) تموم بشه،
     * این ردیف به STATUS_ACTIVE تغییر می‌کنه و starts_at/ends_at ست می‌شه.
     * (نگاه کن به SubscriptionService::activateNextQueuedSubscription)
     */
    const STATUS_QUEUED = 'queued';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'tokens_used' => 'integer',
            'period_months' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tokenUsages(): HasMany
    {
        return $this->hasMany(TokenUsage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeQueued($query)
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isExpired(): bool
    {
        // یک اشتراک queued هنوز starts_at/ends_at نداره، پس منقضی حساب نمی‌شه.
        if ($this->isQueued()) {
            return false;
        }

        return $this->ends_at && $this->ends_at->isPast();
    }

    public function remainingTokens(): int
    {
        return max(0, $this->plan->token_limit - $this->tokens_used);
    }

    public function hasRemainingTokens(): bool
    {
        return $this->remainingTokens() > 0;
    }

    /** کریدیت کل این اشتراک؛ ردیف‌های قدیمی (بدون credit_limit) همان credit پلن را دارند. */
    public function creditLimit(): float
    {
        return (float) ($this->credit_limit ?? $this->plan->credit ?? 0);
    }

    public function remainingCredit(): float
    {
        return max(0, $this->creditLimit() - (float) $this->credit_used);
    }

    public function creditUsagePercentage(): float
    {
        $limit = $this->creditLimit();

        if ($limit <= 0) {
            return 0;
        }

        return round(((float) $this->credit_used / $limit) * 100, 2);
    }

    /** Article cap for the whole period: the plan's monthly article_limit × months bought (1, 3 or 6). 0 = no cap. */
    public function articleLimit(): int
    {
        return (int) $this->plan->article_limit * max(1, (int) $this->period_months);
    }

    /**
     * Articles the user created since this subscription started, across all their sites (deleted ones too).
     * Each product article counts as one article. Failed ones don't count: the user got nothing.
     */
    public function articlesUsed(): int
    {
        $siteIds = Site::withTrashed()->where('user_id', $this->user_id)->select('id');
        $from = $this->starts_at ?? $this->created_at;

        return Article::query()
            ->whereIn('website_id', $siteIds)
            ->where('created_at', '>=', $from)
            ->where('status', '!=', Article::STATUS_FAILED)
            ->count()
            + ProductArticle::query()
                ->whereIn('site_id', $siteIds)
                ->where('created_at', '>=', $from)
                ->where('status', '!=', ProductArticle::STATUS_FAILED)
                ->count();
    }

    public function tokenUsagePercentage(): float
    {
        if ($this->plan->token_limit <= 0) {
            return 0;
        }

        return round(($this->tokens_used / $this->plan->token_limit) * 100, 2);
    }
}
