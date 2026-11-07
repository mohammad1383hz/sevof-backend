<?php

namespace App\Http\Controllers\Wp\Agent;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\SiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $site = $this->site($request);

        $articles = Article::where('website_id', $site->id)
            ->latest()
            ->get([
                'id', 'title', 'status', 'publish_status', 'wp_post_id', 'wp_permalink',
                'seo_score', 'word_count', 'published_at', 'last_synced_at', 'created_at',
            ]);

        return response()->json(['data' => $articles]);
    }

    public function show(Request $request, Article $article): JsonResponse
    {
        $site = $this->site($request);

        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');

        return response()->json([
            // افزونه‌های نصب‌شده آی‌دی عددی می‌خواهند
            'data' => $article->load('sections:id,article_id,heading,status')->makeHidden('token'),
        ]);
    }

    private function site(Request $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('site_connection');

        return $connection->site;
    }
}
