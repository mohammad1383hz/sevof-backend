<?php

namespace App\Services\AI;

class ChatModelResolver
{
    /**
     * فقط دو مدل متنی وجود دارد (id ها همان‌هایی‌اند که فرانت می‌فرستد):
     *  - youtix-mini  : رایگان، همان مدل قبلی gpt-4o-mini
     *  - youtix-2-pro : فقط کاربر پولی، gpt-5.6-luna
     * این تنها منبع معتبر سمت سرور است — حتی اگر کاربر با دستکاری
     * درخواست، model_id غیرمجاز بفرستد، همینجا کنترل می‌شود.
     */
    public const FREE_MODEL_ID = 'sevof-mini';

    public const PRO_MODEL_ID = 'sevof-2-pro';

    public const FREE_API_MODEL = 'gpt-4o-mini';

public const PRO_API_MODEL = 'gpt-5.4-mini';

    /** Outline/هدینگ/متا در Pro: کیفیت پیروی از قوانین خیلی بهتر از mini با کسری از هزینه‌ی luna. */
    public const PRO_PLANNING_API_MODEL = 'gpt-5-mini';

    /** مدل مراحل ساختار و متا بر اساس مدل متنِ انتخاب‌شده‌ی مقاله (selected_model). */
    public static function planningModelFor(?string $selectedApiModel): string
    {
        return $selectedApiModel === self::PRO_API_MODEL ? self::PRO_PLANNING_API_MODEL : self::FREE_API_MODEL;
    }

    public const DEFAULT_MODEL_ID = self::FREE_MODEL_ID;

    /** id های قدیمی (تنظیمات ایجنت ذخیره‌شده در DB و فرانت فعلی) → id جدید */
    protected const LEGACY_IDS = [
        'youtix-mini' => self::FREE_MODEL_ID,
        'youtix-2-pro' => self::PRO_MODEL_ID,
        'gpt-5-nano' => self::FREE_MODEL_ID,
        'gpt-5.4-mini' => self::PRO_MODEL_ID,
        'gpt-5.6-luna' => self::PRO_MODEL_ID,
    ];

    /** همه‌ی id های قابل‌قبول در ورودی (برای validation) */
    public static function acceptedIds(): array
    {
        return [self::FREE_MODEL_ID, self::PRO_MODEL_ID, ...array_keys(self::LEGACY_IDS)];
    }

    /**
     * شناسه‌ی مدلِ درخواستی از فرانت را اعتبارسنجی و مطابق پلن کاربر resolve می‌کند.
     * خروجی، مدل واقعیِ آماده برای پاس‌دادن به chat() است.
     */
    public static function resolve(?string $requestedModelId, bool $isProUser): string
    {
        if (! $isProUser) {
            return self::FREE_API_MODEL;
        }

        // کاربر پولی فقط با انتخاب صریح Mini، Mini می‌گیرد؛ خالی/ناشناخته → Pro.
        $modelId = self::LEGACY_IDS[(string) $requestedModelId] ?? $requestedModelId;

        return $modelId === self::FREE_MODEL_ID ? self::FREE_API_MODEL : self::PRO_API_MODEL;
    }
}
