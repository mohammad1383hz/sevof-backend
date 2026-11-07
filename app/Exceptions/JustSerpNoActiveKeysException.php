<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * پرتاب می‌شود وقتی هیچ ردیف active در جدول justserp_keys وجود ندارد.
 * این یک خطای «تنظیمات/پیکربندی» است، نه خطای موقتی شبکه یا اتمام
 * اعتبار یک کلید مشخص — بنابراین باید از حالت‌های دیگر (که فقط باعث
 * fallback به [] می‌شوند) متمایز باشد و در صورت catch عمومی Throwable
 * دوباره پرتاب (rethrow) شود.
 */
final class JustSerpNoActiveKeysException extends RuntimeException
{
}
