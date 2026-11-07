<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AgentApi\PublicAgentStartJob;
use App\Models\Article;
use App\Models\ArticleGenerationTask;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PublicArticleApiController extends Controller
{
    public function articles(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Article::whereIn('website_id', $this->siteIds($request))
                ->latest()
                ->get(['id', 'token', 'title', 'slug', 'status', 'created_at']),
        ]);
    }

    public function article(Request $request, Article $article): JsonResponse
    {
        abort_unless($this->siteIds($request)->contains($article->website_id), 404);

        $article->load(['sections', 'banners']);
        $data = $article->toArray();
        $data['banner'] = $article->banners->first();
        unset($data['banners']);

        return response()->json($data);
    }

    public function createTask(Request $request): JsonResponse
    {
        $site = $this->site($request);
        abort_unless($site, 422, 'No site is available for this API user.');

        $task = ArticleGenerationTask::create([
            'id' => 'task_'.Str::lower(Str::random(24)),
            'site_id' => $site->id,
            'status' => ArticleGenerationTask::STATUS_RUNNING,
        ]);

        PublicAgentStartJob::dispatch($site, $task);

        return response()->json([
            'id' => $task->id,
            'status' => $task->status,
        ], 202);
    }

    public function task(Request $request, ArticleGenerationTask $task): JsonResponse
    {
        abort_unless($this->siteIds($request)->contains($task->site_id), 404);

        $task->markFromArticle($task->article);
        $task->refresh();

        $payload = ['id' => $task->id, 'status' => $task->status];

        if ($task->status === ArticleGenerationTask::STATUS_COMPLETED) {
            $payload['article_id'] = $task->article?->token;
        }

        if ($task->status === ArticleGenerationTask::STATUS_FAILED) {
            $payload['error'] = $task->error_message;
        }

        return response()->json($payload);
    }

    private function siteIds(Request $request): Collection
    {
        return collect([$this->site($request)->id]);
    }

    private function site(Request $request): ?Site
    {
        return $request->attributes->get('public_api_site');
    }
}
