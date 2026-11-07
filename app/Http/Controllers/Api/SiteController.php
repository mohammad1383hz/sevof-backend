<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\StoreSiteRequest;
use App\Http\Resources\SiteResource;
use App\Jobs\AnalyzeSiteJob;
use App\Models\Site;
use App\Services\Crawler\SiteCrawlerService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SiteController extends Controller
{
    private const MAX_FREE_DOMAIN_REGISTRATIONS = 4;

    /**
     * نمایش لیست دامنه‌های کاربر.
     * دامنه‌هایی که Soft Delete شده‌اند به‌صورت خودکار نمایش داده نمی‌شوند.
     */
    public function index(Request $request): JsonResponse
    {
        $sites = $request->user()
            ->sites()
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => SiteResource::collection($sites),
            'meta' => [
                'current_page' => $sites->currentPage(),
                'last_page' => $sites->lastPage(),
                'per_page' => $sites->perPage(),
                'total' => $sites->total(),
            ],
        ]);
    }

    /**
     * ثبت دامنه و شروع تحلیل.
     * اگر دامنه قبلاً Soft Delete شده باشد، همان رکورد Restore می‌شود.
     */
public function store(
    StoreSiteRequest $request,
    SiteCrawlerService $crawler,
    SubscriptionService $subscriptionService,
): JsonResponse {
    try {
        $normalized = $crawler->normalizeDomain($request->validated('domain'));
    } catch (InvalidArgumentException $exception) {
        return response()->json([
            'message' => $exception->getMessage(),
        ], 422);
    }

    $user = $request->user();
    $forceReanalyze = $request->boolean('force');

    // فقط رکوردِ خودِ همین کاربر (حتی Soft Delete شده) را پیدا می‌کنیم.
    // رکورد کاربران دیگر هیچ‌وقت نباید آپدیت یا به این کاربر برگردانده شود.
    $existing = Site::withTrashed()
        ->where('domain', $normalized['domain'])
        ->where('user_id', $user->id)
        ->first();

    // کاربر پولی بدون محدودیت دامنه‌ی تکراری ثبت می‌کند.
    // برای کاربر رایگان هر دامنه حداکثر MAX_FREE_DOMAIN_REGISTRATIONS بار (بین همه‌ی کاربران) ثبت می‌شود.
    if (! $existing && ! $subscriptionService->hasActivePaidPlan($user)) {
        $registrations = Site::where('domain', $normalized['domain'])
            ->where('user_id', '!=', $user->id)
            ->count(); // Soft Delete شده‌ها خودکار حساب نمی‌شوند

        if ($registrations >= self::MAX_FREE_DOMAIN_REGISTRATIONS) {
            return response()->json([
                'message' => 'This domain has reached its registration limit. If you didn\'t register it yourself, please contact support.',
                'already_analyzed' => false,
                'site' => null,
            ], 409);
        }
    }

    if ($existing?->trashed()) {
        $existing->restore();
        $existing->update([
            'url' => $normalized['url'],
            'status' => Site::STATUS_PENDING,
            'niche' => null,
            'summary' => null,
            'crawled_pages' => null,
            'ai_response' => null,
            'error_message' => null,
            'analyzed_at' => null,
        ]);

        AnalyzeSiteJob::dispatch($existing->fresh());

        return response()->json([
            'message' => 'Domain reactivated and analysis started.',
            'already_analyzed' => false,
            'site' => new SiteResource($existing->fresh()),
        ], 202);
    }

    if ($existing) {
        if (in_array($existing->status, [
            Site::STATUS_PENDING,
            Site::STATUS_CRAWLING,
            Site::STATUS_ANALYZING,
        ], true)) {
            return response()->json([
                'message' => 'This domain is being analyzed.',
                'already_analyzed' => false,
                'site' => new SiteResource($existing),
            ]);
        }

        if ($existing->status === Site::STATUS_COMPLETED && ! $forceReanalyze) {
            return response()->json([
                'message' => 'This domain has already been analyzed.',
                'already_analyzed' => true,
                'site' => new SiteResource($existing),
            ]);
        }

        $existing->update([
            'status' => Site::STATUS_PENDING,
            'niche' => null,
            'summary' => null,
            'crawled_pages' => null,
            'ai_response' => null,
            'error_message' => null,
            'analyzed_at' => null,
        ]);

        AnalyzeSiteJob::dispatch($existing->fresh());

        return response()->json([
            'message' => 'Domain re-analysis started.',
            'already_analyzed' => false,
            'site' => new SiteResource($existing->fresh()),
        ], 202);
    }

    $site = $user->sites()->create([
        'domain' => $normalized['domain'],
        'url' => $normalized['url'],
        'status' => Site::STATUS_PENDING,
    ]);

    AnalyzeSiteJob::dispatch($site);

    return response()->json([
        'message' => 'Domain added. Site analysis started.',
        'already_analyzed' => false,
        'site' => new SiteResource($site),
    ], 202);
}

    /**
     * نمایش اطلاعات یک دامنه.
     * Route Model Binding دامنه‌های Soft Delete شده را پیدا نمی‌کند.
     */
    public function show(Request $request, Site $site): JsonResponse
    {
        if ((int) $site->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        return response()->json([
            'site' => new SiteResource($site),
        ]);
    }

    /**
     * حذف نرم دامنه.
     * رکورد از دیتابیس حذف نمی‌شود و فقط deleted_at مقدار می‌گیرد.
     */
    public function destroy(Request $request, Site $site): JsonResponse
    {
        if ((int) $site->user_id !== (int) $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $site->delete();

        return response()->json([
            'message' => 'Domain removed.',
        ]);
    }
}
