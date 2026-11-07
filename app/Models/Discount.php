<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $guarded = ['id'];

    /**
     * کاراکترهایی که برای ساخت کد استفاده می‌شن.
     * عمداً 0/O و 1/I/L حذف شدن که کد وقتی با صدا یا چشم خونده می‌شه اشتباه گرفته نشه.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 5;

    protected static function booted(): void
    {
        static::creating(function (Discount $discount) {
            if (empty($discount->code)) {
                $discount->code = self::generateUniqueCode();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'activate_on_login' => 'boolean',
            'is_active'         => 'boolean',
            'activated_at'      => 'datetime',
            'expires_at'        => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('user_id');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', strtoupper($code));
    }

    // تخفیفی که قراره با لاگین فعال بشه ولی هنوز فعال نشده
    public function isPendingActivation(): bool
    {
        return $this->activate_on_login && $this->activated_at === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    // تخفیفی که همین الان قابل استفاده‌ست
    public function isUsable(): bool
    {
        return $this->is_active
            && ! $this->isPendingActivation()
            && ! $this->isExpired();
    }

    // فعال‌سازی تخفیف با لاگین کاربر (idempotent - اگه قبلاً فعال شده دوباره کاری نمی‌کنه)
    public function activate(): void
    {
        if ($this->activated_at !== null) {
            return;
        }

        $this->activated_at = now();

        if ($this->duration_minutes) {
            $this->expires_at = now()->addMinutes($this->duration_minutes);
        }

        $this->save();
    }

    /**
     * یه کد ۵ کاراکتری یکتا (حروف بزرگ انگلیسی + عدد) می‌سازه.
     * چون کد باید unique باشه، قبل از برگردوندن، توی خود دیتابیس هم چک می‌کنه
     * که تصادفاً با کد یه رکورد قبلی (حتی رکورد غیرفعال/منقضی‌شده) برخورد نکنه.
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = self::randomCode();
        } while (self::query()->byCode($code)->exists());

        return $code;
    }

    private static function randomCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;

        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
