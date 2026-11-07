<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * این میدل‌ور مطمئن می‌شود که کاربر احراز هویت‌شده فعلی واقعاً از نوع
 * App\Models\Admin است (نه Applicant و نه Card)، تا داوطلب‌های عادی
 * نتوانند با توکن خودشان به روت‌های پنل ادمین دسترسی پیدا کنند.
 *
 * ثبت در app/Http/Kernel.php:
 *   'admin' => \App\Http\Middleware\EnsureAdmin::class,
 *
 * استفاده در روت:
 *   Route::middleware(['auth:sanctum', 'admin'])->group(...)
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof Admin || !$user->is_active) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
