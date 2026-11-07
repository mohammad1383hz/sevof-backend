<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteConnection;
use App\Services\Wp\WordPressConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WordPressConnectionController extends Controller
{
    public function __construct(
        private readonly WordPressConnectionService $connectionService,
    ) {
    }

  public function show(Request $request, Site $site): JsonResponse
{
    $this->authorizeSiteAccess($request, $site);

    $connection = $site->connection;

    return response()->json([
        // همان شرطِ ArticlePublisherResolver
        'provider' => $site->shopifyConnection ? 'shopify' : 'wordpress',
        'data'     => $connection,
    ]);
}

    /**
     * ایجاد اتصال جدید — client_secret فقط همین یک‌بار در پاسخ برگردانده می‌شود
     * تا کاربر آن را در پلاگین وردپرس وارد کند.
     */
    public function connect(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $result = $this->connectionService->connect($site);

        return response()->json([
            'message' => 'Connection details created. Enter them in the WordPress plugin settings.',
            'data' => [
                'client_id' => $result['connection']->client_id,
                'client_secret' => $result['client_secret'],
                'api_key' => $result['connection']->api_key,
            ],
        ], 201);
    }

    public function reconnect(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = $this->findConnectionOrFail($site);
        $result = $this->connectionService->reconnect($connection);

        return response()->json([
            'message' => 'Connection details regenerated. Reconfigure the WordPress plugin with the new details.',
            'data' => [
                'client_id' => $result['connection']->client_id,
                'client_secret' => $result['client_secret'],
                'api_key' => $result['connection']->api_key,
            ],
        ]);
    }

    public function disconnect(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = $this->findConnectionOrFail($site);
        $connection = $this->connectionService->disconnect($connection);

        return response()->json(['message' => 'Disconnected.', 'data' => $connection]);
    }

    public function test(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = $this->findConnectionOrFail($site);
        $result = $this->connectionService->testConnection($connection);

        return response()->json([
            'message' => $result['ok'] ? 'Connection is working.' : ($result['message'] ?? 'Connection failed.'),
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }

    private function findConnectionOrFail(Site $site): SiteConnection
    {
        $connection = $site->connection;

        abort_if(! $connection, 404, 'No connection is set up for this site.');

        return $connection;
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }
}
