<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteResource;
use App\Jobs\AnalyzeSiteKeywordsJob;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteKeywordController extends Controller
{
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
                'site' => new SiteResource($site),
            ]);
        }

        $site->update([
            'keyword_analysis_status' => Site::KEYWORD_STATUS_PENDING,
            'final_keyword' => null,
            'final_title' => null,
            'keyword_analysis_error' => null,
        ]);

        AnalyzeSiteKeywordsJob::dispatch($site);

        return response()->json([
            'message' => 'Keyword analysis started.',
            'site' => new SiteResource($site->fresh()),
        ], 202);
    }

    public function trends(Request $request, Site $site): JsonResponse
    {
        if ($site->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'site' => new SiteResource($site),
            'trends' => $site->keywordTrends()->latest()->get(),
        ]);
    }
}