<?php

namespace App\Services\AI;

use App\Models\User;
use App\Services\TokenUsageService;
use Illuminate\Support\Facades\Log;

trait RecordsTokenUsage
{
    protected function recordAiTokenUsage(
        User $user,
        string $serviceName,
        array $usage,
        ?string $model = null,
        ?array $metadata = null,
    ): void {
        try {
            $tokenUsage = app(TokenUsageService::class)->recordUsageFromResponse(
                user: $user,
                serviceName: $serviceName,
                usageResponse: $usage,
                model: $model,
                metadata: $metadata,
            );

            // recordUsageFromResponse در صورت نبود اشتراک فعال یا هر مشکل دیگه
            // که استثنا پرتاب نکنه، ساکت null برمی‌گردونه؛ این حالت رو هم
            // باید لاگ کنیم وگرنه مصرف واقعی بی‌صدا از دست می‌ره.
            if ($tokenUsage === null) {
                Log::warning('token_usage_recording_skipped', [
                    'user_id' => $user->id,
                    'service' => $serviceName,
                    'model' => $model,
                    'reason' => 'recordUsageFromResponse returned null (likely no active subscription)',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('token_usage_recording_failed', [
                'user_id' => $user->id,
                'service' => $serviceName,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
