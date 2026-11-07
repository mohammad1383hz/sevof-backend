<?php

namespace App\Http\Controllers\Wp\Agent;

use App\Http\Controllers\Controller;
use App\Models\SiteConnection;
use App\Services\Wp\AgentDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentDashboardController extends Controller
{
    public function __construct(
        private readonly AgentDashboardService $dashboardService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $site = $this->site($request);

        return response()->json(['data' => $this->dashboardService->summary($site)]);
    }

    public function keywords(Request $request): JsonResponse
    {
        $site = $this->site($request);

        return response()->json(['data' => $this->dashboardService->keywords($site)]);
    }

    public function entities(Request $request): JsonResponse
    {
        $site = $this->site($request);

        return response()->json(['data' => $this->dashboardService->entities($site)]);
    }
public function subscription(Request $request): JsonResponse
{
    $site = $this->site($request);

    return response()->json(['data' => $this->dashboardService->subscription($site)]);
}
    private function site(Request $request)
    {
        /** @var SiteConnection $connection */
        $connection = $request->attributes->get('site_connection');

        return $connection->site;
    }
    public function productArticles(Request $request): JsonResponse
{
    $site = $this->site($request);

    return response()->json(['data' => $this->dashboardService->productArticles($site)]);
}
}
