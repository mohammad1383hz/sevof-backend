<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * شناسه‌ی عمومی (ستون token، ULID) به‌جای آی‌دی عددی در آدرس‌ها، مسیرهای API و خروجی JSON.
 * آی‌دی عددی داخل دیتابیس و بک‌اند (کلیدهای خارجی، Jobها، ادمین) همان‌طور می‌ماند.
 */
trait HasPublicToken
{
    public static function bootHasPublicToken(): void
    {
        static::creating(function ($model): void {
            $model->token ??= self::newPublicToken();
        });
    }

    public static function newPublicToken(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    /** افزونه‌ی وردپرس و کلاینت‌های API عمومی ممکن است هنوز آی‌دی عددی قدیمی بفرستند. */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if ($field === null && ctype_digit((string) $value) && request()->is('api/agent/*')) {
            return $query->where($this->qualifyColumn($this->getKeyName()), $value);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /** پیدا کردن با توکن از ورودی درخواست (بدنه یا کوئری) — همان کاری که route binding می‌کند. */
    public static function idFromToken(mixed $token): ?int
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $id = static::query()->where('token', $token)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** در خروجی JSON فیلد id همان توکن است؛ اگر token انتخاب نشده باشد (select محدود) id عددی می‌ماند. */
    public function toArray()
    {
        $data = parent::toArray();

        if (isset($data['token'])) {
            $data['id'] = $data['token'];
            unset($data['token']);
        }

        return $data;
    }
}
