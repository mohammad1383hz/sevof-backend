<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** درخواست همکاری در فروش (کسب درآمد با معرفی)؛ فعلاً بررسی دستی، بدون کد معرف. */
class ReferralRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /** جاهایی که کاربر می‌تواند یوتیکس را معرفی کند (کلید => برچسب فارسی). */
    public const CHANNELS = [
        'instagram' => 'Instagram',
        'telegram' => 'Telegram',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'x' => 'X (Twitter)',
        'facebook' => 'Facebook',
        'website' => 'Website or blog',
        'linkedin' => 'LinkedIn',
        'courses' => 'Course or class',
        'clients' => 'My own clients (agency or freelancer)',
        'other' => 'Other',
    ];

    public const AUDIENCE_SIZES = [
        'under_1k' => 'Fewer than 1,000',
        '1k_10k' => '1,000–10,000',
        '10k_50k' => '10,000–50,000',
        '50k_plus' => 'More than 50,000',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** شماره‌ی موبایل ایران به شکل 09xxxxxxxxx؛ ارقام فارسی/عربی و +98 هم پذیرفته می‌شود. null یعنی نامعتبر. */
    /** International phone in E.164 form (+14155550123); local digits are converted first. null if invalid. */
    public static function normalizePhone(?string $phone): ?string
    {
        $phone = strtr((string) $phone, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $phone = (string) preg_replace('/[\s\-().]+/', '', $phone);
        $phone = (string) preg_replace('/^00/', '+', $phone);

        return preg_match('/^\+[1-9]\d{6,14}$/', $phone) ? $phone : null;
    }
}
