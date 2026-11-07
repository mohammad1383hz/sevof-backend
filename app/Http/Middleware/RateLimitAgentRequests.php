<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit بر اساس X-Agent-Key (یا IP اگر هدر موجود نبود).
 * از RateLimiter داخلی لاراول استفاده می‌کند، فقط بدون نیاز به تعریف
 * limiter در ServiceProvider — تا وابستگی اضافه به فایل‌های دیگر نداشته باشیم.
 */
class RateLimitAgentRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'agent-rate:'.($request->header('X-Agent-Key') ?: $request->ip());

        $maxAttempts = (int) config('wordpress_agent.rate_limit_max_attempts', 60);
        $decaySeconds = (int) config('wordpress_agent.rate_limit_decay_seconds', 60);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return response()->json([
                'message' => 'Too many requests.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, $decaySeconds);

        return $next($request);
    }
}
