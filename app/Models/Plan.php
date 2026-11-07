<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasPublicToken;
    use HasFactory;

    /** مدت‌های قابل خرید (ماه) */
    public const PERIODS = [1, 3, 6];

   protected $guarded = [
        'id',
    ];
    protected function casts(): array
    {
        return [
            'token_limit' => 'integer',
            'site_limit' => 'integer',
            'article_limit' => 'integer',
            'is_active' => 'boolean',
            'is_free' => 'boolean',
            'price' => 'integer',
            'quarterly_discount_percentage' => 'integer',
            'semiannual_discount_percentage' => 'integer',
            'features' => 'array',
        ];
    }
public function getFinalPriceAttribute(): int
    {
        return $this->priceFor(1);
    }

    /** مدت‌هایی که این پلن واقعاً قابل خرید است؛ پلن رایگان فقط ماهانه. */
    public function availablePeriods(): array
    {
        return $this->is_free ? [1] : self::PERIODS;
    }

    public function discountPercentFor(int $months): int
    {
        $percent = match ($months) {
            3 => $this->quarterly_discount_percentage,
            6 => $this->semiannual_discount_percentage,
            default => $this->discount_percentage,
        };

        return (int) min(100, max(0, (int) $percent));
    }

    /** قیمت خام کل دوره (قبل از تخفیف) */
    public function originalPriceFor(int $months): int
    {
        return (int) $this->price * $months;
    }

    /** قیمت نهایی کل دوره، با تخفیف همان دوره */
    public function priceFor(int $months): int
    {
        $original = $this->originalPriceFor($months);

        return (int) max(0, round($original - $original * $this->discountPercentFor($months) / 100));
    }

    /** کریدیت کل دوره: کریدیت ماهانه‌ی پلن × تعداد ماه */
    public function creditFor(int $months): float
    {
        return (float) $this->credit * $months;
    }
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFree($query)
    {
        return $query->where('is_free', true);
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }
}
