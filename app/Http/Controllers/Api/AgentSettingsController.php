<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDailyArticleJob;
use App\Jobs\GenerateDailyIdeasJob;
use App\Jobs\RefreshAutopilotContentResearchJob;
use App\Models\AgentSetting;
use App\Models\Site;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentSettingsController extends Controller
{
    public function show(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        return response()->json([
            'data' => AgentSetting::firstOrCreate(['site_id' => $site->id]),
        ]);
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $setting = AgentSetting::firstOrCreate(['site_id' => $site->id]);

        $validated = $request->validate([
            'agent_enabled' => 'sometimes|boolean',
            'refresh_enabled' => 'sometimes|boolean',
            'ideas_enabled' => 'sometimes|boolean',
            'articles_enabled' => 'sometimes|boolean',
            'daily_idea_count' => 'sometimes|nullable|integer|min:1|max:20',
            'daily_article_limit' => 'sometimes|nullable|integer|min:1|max:20',
            'default_publish_status' => 'sometimes|in:draft,publish',
            'daily_start_hour' => 'sometimes|integer|min:0|max:23',
            'publish_interval_days' => 'sometimes|integer|min:1|max:30',
            'content_model' => 'sometimes|string|in:' . implode(',', config('ai_models.content_models')),
            'image_generation_enabled' => 'sometimes|boolean',
            'image_model' => 'sometimes|nullable|string|in:' . implode(',', config('ai_models.image_models')),
            'images_per_article' => 'sometimes|nullable|integer|min:0|max:10',
            'image_user_prompt' => 'sometimes|nullable|string|max:500',

            // ⬅️ جدید: prompt دلخواه برای متن مقاله
            'article_user_prompt' => 'sometimes|nullable|string|max:2000',

            // ⬅️ جدید: بازه‌ی تعداد بخش مقاله
            'min_sections' => 'sometimes|nullable|integer|min:1|max:50',
            'max_sections' => 'sometimes|nullable|integer|min:1|max:50',
        ]);

        // پاک‌سازی prompt تصویر شاخص
        if (array_key_exists('image_user_prompt', $validated)) {
            $validated['image_user_prompt'] = $this->normalizeUserPrompt($validated['image_user_prompt'], 500);
        }

        // پاک‌سازی prompt متن مقاله
        if (array_key_exists('article_user_prompt', $validated)) {
            $validated['article_user_prompt'] = $this->normalizeUserPrompt($validated['article_user_prompt'], 2000);
        }

        // اعتبارسنجی منطقی بازه‌ی بخش‌ها: باید ببینیم بعد از merge با مقادیر فعلی، min <= max باشه
        $resolvedMin = array_key_exists('min_sections', $validated) ? $validated['min_sections'] : $setting->min_sections;
        $resolvedMax = array_key_exists('max_sections', $validated) ? $validated['max_sections'] : $setting->max_sections;

        if ($resolvedMin !== null && $resolvedMax !== null && $resolvedMin > $resolvedMax) {
            return response()->json([
                'message' => 'The minimum number of sections can\'t exceed the maximum.',
                'errors' => [
                    'min_sections' => ['The minimum number of sections can\'t exceed the maximum.'],
                ],
            ], 422);
        }

        // روشن‌کردن اتوماسیون فقط با اشتراک پولی فعال و کردیت باقی‌مانده (خاموش‌کردن همیشه آزاد است)
        if (($validated['agent_enabled'] ?? false) && ! $setting->agent_enabled) {
            $subscriptions = app(SubscriptionService::class);

            if (! $subscriptions->hasActivePaidPlan($request->user())) {
                return response()->json([
                    'code' => 'subscription_required',
                    'message' => 'Smart automation is a Pro feature. Upgrade your plan to turn it on.',
                ], 402);
            }

            if (! $subscriptions->hasAvailableCredit($request->user())) {
                return response()->json([
                    'code' => 'insufficient_credit',
                    'message' => 'Your plan credit is used up. Renew your plan to turn on automation.',
                ], 402);
            }
        }

        // به‌روزرسانی خودکار مقاله‌ها هم بخشی از اتوماسیون Pro است
        if (($validated['refresh_enabled'] ?? false) && ! $setting->refresh_enabled
            && ! app(SubscriptionService::class)->hasActivePaidPlan($request->user())) {
            return response()->json([
                'code' => 'subscription_required',
                'message' => 'Automatic article updates are a Pro feature.',
            ], 402);
        }

        // اگه daily_start_hour تغییر کرده، زمان‌بندی بعدی ریست بشه
        if (array_key_exists('daily_start_hour', $validated)
            && (int) $validated['daily_start_hour'] !== (int) $setting->daily_start_hour) {
            $validated['next_articles_run_at'] = null;
        }

        $setting->update($validated);

        return response()->json([
            'data' => $setting,
        ]);
    }

    /**
     * پاک‌سازی مشترک برای هر prompt متنی ورودی کاربر (تگ/خط‌جدید/فاصله‌ی اضافه حذف می‌شود).
     * اگر بعد از پاک‌سازی خالی بماند null برمی‌گرداند.
     */
    protected function normalizeUserPrompt(?string $value, int $maxLength): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        $value = str_replace(['<', '>'], '', $value);
        $value = mb_substr($value, 0, $maxLength);

        return $value !== '' ? $value : null;
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }
}
