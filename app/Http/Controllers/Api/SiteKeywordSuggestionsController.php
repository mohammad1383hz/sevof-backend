<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Jobs\AnalyzeSiteSuggestionsKeywordsJob;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteKeywordSuggestionsController extends Controller
{
    /**
     * شروع فرآیند تحلیل کلمات کلیدی سایت (Autocomplete + AI)
     */
    public function analyze(Request $request, Site $site): JsonResponse
    {
        if ($site->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (empty($site->keywords)) {
            return response()->json([
                'message' => 'The initial site analysis and keyword extraction must finish first.',
            ], 422);
        }

        if (in_array($site->keyword_analysis_status, [
            Site::KEYWORD_STATUS_PENDING,
            Site::KEYWORD_STATUS_PROCESSING,
        ], true)) {
            return response()->json([
                'message' => 'Keyword analysis for this site is in progress.',
                'site'    => new SiteResource($site),
            ]);
        }

        $site->update([
            'keyword_analysis_status' => Site::KEYWORD_STATUS_PENDING,
            'final_keyword'           => null,
            'final_title'             => null,
            'keyword_analysis_error'  => null,
        ]);

        AnalyzeSiteSuggestionsKeywordsJob::dispatch($site);

        return response()->json([
            'message' => 'Keyword analysis started.',
            'site'    => new SiteResource($site->fresh()),
        ], 202);
    }

    /**
     * مشاهده نتایج پیشنهادات autocomplete ذخیره‌شده برای سایت
     */
    public function suggestions(Request $request, Site $site): JsonResponse
    {
        if ($site->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'site'        => new SiteResource($site),
            'suggestions' => $site->keywordSuggestions()->latest()->get(),
        ]);
    }
}