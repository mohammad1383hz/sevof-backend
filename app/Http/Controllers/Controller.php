<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeSiteJob;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * سایت هنوز Entity ندارد (ensureEntity هم نتوانست از تحلیل قبلی بسازد).
     * کاربر پیام فنی نمی‌بیند: اگر تحلیل در جریان است صبر می‌کند، وگرنه تحلیل سایت خودکار دوباره شروع می‌شود.
     */
    protected function siteNotReadyResponse(Site $site): JsonResponse
    {
        if (! $site->isAnalysisRunning()) {
            $site->update(['status' => Site::STATUS_PENDING, 'error_message' => null]);
            AnalyzeSiteJob::dispatch($site->fresh());
        }

        return response()->json([
            'code' => 'site_analysis_in_progress',
            'message' => 'We\'re preparing your site analysis. Try again in a minute or two.',
        ], 409);
    }
}
