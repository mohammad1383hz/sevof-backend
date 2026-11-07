<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
class Site extends Model
{
    use HasPublicToken;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CRAWLING = 'crawling';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

 protected $guarded = [
        'id',
    ];

    public function shopifyConnection()
    {
        return $this->hasOne(ShopifyConnection::class);
    }
    protected function casts(): array
    {
        return [
            'crawled_pages' => 'array',
            'ai_response' => 'array',
             'categories' => 'array',   // ⬅️ اضافه کن
        'tags' => 'array',
            'analyzed_at' => 'datetime',
                'keywords' => 'array', // این خط جدیده

        ];
    }
// در Site.php

public const KEYWORD_STATUS_NOT_STARTED = 'not_started';
public const KEYWORD_STATUS_PENDING = 'pending';
public const KEYWORD_STATUS_PROCESSING = 'processing';
public const KEYWORD_STATUS_DONE = 'done';
public const KEYWORD_STATUS_FAILED = 'failed';


public function articleIdeas(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(ArticleIdea::class, 'website_id');
}

/**
 * شماره‌ی دسته‌ی تازه برای ایده‌ها، یکتا بین درخواست دستی، اتوماسیون و API.
 * از بزرگ‌ترین شماره‌ی موجود (شمارنده‌ی سایت یا ایده‌های ذخیره‌شده) یکی جلوتر است، تا دو دسته هرگز یک شماره نگیرند.
 */
public function reserveIdeaBatch(): int
{
    return \Illuminate\Support\Facades\DB::transaction(function () {
        $current = (int) static::query()->whereKey($this->getKey())->lockForUpdate()->value('idea_generation_batch');
        $next = max($current, (int) ArticleIdea::where('website_id', $this->getKey())->max('batch')) + 1;

        static::query()->whereKey($this->getKey())->update(['idea_generation_batch' => $next]);
        $this->idea_generation_batch = $next;
        $this->syncOriginalAttribute('idea_generation_batch');

        return $next;
    });
}
public function keywordTrends(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(SiteKeywordTrend::class);
}
public function keywordSuggestions(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(SiteKeywordSuggestion::class);
}
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
public function usedKeywords(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(SiteUsedKeyword::class);
}
    public function markAs(string $status, ?string $errorMessage = null): void
    {
        $this->update([
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    public function entity(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\WebsiteEntity::class, 'website_id');
}

    /**
     * Entity سایت. اگر ردیفش نیست (یا Primary خالی است) ولی خروجی تحلیل سایت آن را دارد،
     * بی‌صدا و بدون فراخوانی AI از همان بازسازی می‌شود. null یعنی تحلیل واقعاً Entity ندارد.
     */
    public function ensureEntity(): ?WebsiteEntity
    {
        $entity = $this->entity;

        if ($entity && ! empty($entity->primary_entity['name'])) {
            return $entity;
        }

        $analysis = (array) $this->ai_response;
        $primary = $analysis['primary_entity'] ?? null;

        if (! is_array($primary) || empty($primary['name'])) {
            return null;
        }

        $entity = WebsiteEntity::updateOrCreate(
            ['website_id' => $this->id],
            ['primary_entity' => $primary, 'core_entities' => $analysis['core_entities'] ?? []]
        );

        $this->setRelation('entity', $entity);

        return $entity;
    }

    /**
     * نام برند سایت از روی عنوان صفحات کراول‌شده (مثلاً «ایران باتو | بهترین قیمت» → «ایران باتو»)؛
     * بخشی از عنوان که در صفحات بیشتری تکرار شده برنده است. اگر نام قابل‌اتکایی نبود، دامنه.
     */
    public function brandName(): string
    {
        $generic = ['About us', 'Contact us', 'Home page', 'Home', 'home', 'about us', 'contact us', 'contact'];
        $counts = [];

        foreach (array_values((array) $this->crawled_pages) as $page) {
            $title = str_replace(['|', '–', '—'], '|', (string) ($page['title'] ?? ''));
            $title = (string) preg_replace('/\s+[-:]\s+|\s+-$/u', '|', $title);

            foreach (explode('|', $title) as $part) {
                $part = trim($part, " \t-:");

                if ($part === '' || in_array(mb_strtolower($part), $generic, true)
                    || preg_match_all('/\p{L}/u', $part) < 3
                    || count(preg_split('/\s+/u', $part)) > 4) {
                    continue;
                }

                $counts[$part] = ($counts[$part] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return (string) $this->domain;
        }

        // بیشترین تکرار؛ در تساوی، اولین بخشِ دیده‌شده (ترتیب درج آرایه حفظ می‌شود)
        return (string) array_search(max($counts), $counts, true);
    }

    /** تحلیل سایت هنوز در جریان است (Entity به‌زودی ساخته می‌شود). */
    public function isAnalysisRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CRAWLING, self::STATUS_ANALYZING], true);
    }
    public function connection(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\SiteConnection::class);
}
public function apiAgents(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(ApiAgent::class);
}

    public function contentChatSessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContentChatSession::class);
    }
}
