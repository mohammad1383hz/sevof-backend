<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Jobs\PublishArticleJob;
use App\Jobs\wp\DeleteArticleFromWordPressJob;
use App\Jobs\wp\SyncArticleStatusJob;
use App\Models\Article;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticlePublishController extends Controller
{
    public function publish(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->authorizeSiteAndArticle($request, $site, $article);

        if ($article->status !== Article::STATUS_COMPLETED) {
            return response()->json(['message' => 'Only completed articles can be published.'], 422);
        }

        PublishArticleJob::dispatch($article, 'publish');

        return response()->json(['message' => 'Publishing to WordPress started.'], 202);
    }

    public function update(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->authorizeSiteAndArticle($request, $site, $article);

        if (! $article->wp_post_id) {
            return response()->json(['message' => 'This article has not been published to WordPress yet.'], 422);
        }

        PublishArticleJob::dispatch($article, 'update');

        return response()->json(['message' => 'Updating the article on WordPress started.'], 202);
    }

    public function destroy(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->authorizeSiteAndArticle($request, $site, $article);

        if (! $article->wp_post_id) {
            return response()->json(['message' => 'This article is not published on WordPress.'], 422);
        }

        DeleteArticleFromWordPressJob::dispatch($article);

        return response()->json(['message' => 'Removing the article from WordPress started.'], 202);
    }

    public function syncStatus(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->authorizeSiteAndArticle($request, $site, $article);

        SyncArticleStatusJob::dispatch($article);

        return response()->json(['message' => 'Article status sync started.'], 202);
    }

    private function authorizeSiteAndArticle(Request $request, Site $site, Article $article): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');
    }
}
