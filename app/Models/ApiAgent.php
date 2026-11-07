<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiAgent extends Model
{
    use HasPublicToken;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * پیشوند کلید‌ها، فقط برای خوانایی و تشخیص محیط.
     */
    private const KEY_PREFIX = 'sk_agent_';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * یک Agent جدید برای کاربر (و در صورت امکان یک سایت مشخص) می‌سازد
     * و کلید خام (plaintext) را فقط همین یک‌بار برمی‌گرداند.
     *
     * @return array{0: self, 1: string} [مدل ساخته‌شده, کلید خام]
     */
    public static function issueFor(User $user, string $name, ?Site $site = null): array
    {
        $plainKey = self::KEY_PREFIX.Str::random(40);

        $agent = self::create([
            'user_id' => $user->id,
            'site_id' => $site?->id,
            'name' => $name,
            'key_hash' => hash('sha256', $plainKey),
            'key_last_four' => substr($plainKey, -4),
        ]);

        return [$agent, $plainKey];
    }

    /**
     * از روی کلید خامی که کلاینت در هدر فرستاده، Agent متناظر را پیدا می‌کند.
     * فقط Agentهای فعال (غیر revoke شده) برگردانده می‌شوند.
     */
    public static function findActiveFromKey(?string $plainKey): ?self
    {
        if (blank($plainKey)) {
            return null;
        }

        return self::query()
            ->where('key_hash', hash('sha256', $plainKey))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * سایتی که این کلید به آن دسترسی دارد.
     * اگر کلید مستقیماً به سایت وصل نباشد (کلیدهای قدیمی)، اولین سایت کاربر برمی‌گردد.
     */
    public function resolveSite(): ?Site
    {
        if ($this->site_id) {
            return $this->site;
        }

        return $this->user?->sites()->orderBy('id')->first();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function markUsedNow(): void
    {
        // touch نکنیم که updated_at رو عوض نکنه؛ فقط ستون مربوطه آپدیت بشه
        $this->timestamps = false;
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
